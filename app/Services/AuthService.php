<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Auth\LoginData;
use App\DTOs\Auth\RegisterData;
use App\Enums\OtpType;
use App\Enums\TwoFactorType;
use App\Enums\UserRole;
use App\Exceptions\SessionExpiredException;
use App\Exceptions\ThrottleException;
use App\Models\Session;
use App\Models\User;
use App\Traits\Transactional;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    use Transactional;

    public function __construct(
        protected OtpService $otpService,
        protected Request $request,
    ) {}

    /**
     * Register a new customer. Sends OTP for contact verification.
     * Folded from RegisterUserAction.
     */
    public function register(RegisterData $data): User
    {
        $user = new User;
        $user->fill([
            'email' => $data->email,
            'phone' => $data->phone,
            'password' => Hash::make($data->password),
        ]);
        // role is non-fillable — assign directly before save for atomic INSERT.
        $user->role = UserRole::CUSTOMER->value;
        $user->save();

        $identifier = $data->email ?? $data->phone;
        $this->otpService->generate((string) $identifier, OtpType::REGISTRATION, $user);

        return $user;
    }

    /**
     * Validate credentials and return the matched User.
     * Folded from LoginUserAction.
     *
     * @throws ValidationException on invalid credentials or cross-verified-contact policy
     */
    public function attemptLogin(LoginData $data): User
    {
        $identifier = $data->identifier;

        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => [__('api.auth.invalid_credentials')],
            ]);
        }

        // Rule: if both email+phone exist, prevent logging in with an unverified contact
        // while the other contact is already verified.
        if ($user->email && $user->phone) {
            $isUsingEmail = ($user->email === $identifier);
            $isUsingPhone = ($user->phone === $identifier);

            if ($isUsingEmail && ! $user->email_verified_at && $user->phone_verified_at) {
                throw ValidationException::withMessages([
                    'identifier' => [__('api.auth.email_not_verified_login_phone')],
                ]);
            }

            if ($isUsingPhone && ! $user->phone_verified_at && $user->email_verified_at) {
                throw ValidationException::withMessages([
                    'identifier' => [__('api.auth.phone_not_verified_login_email')],
                ]);
            }
        }

        return $user;
    }

    /**
     * Verify OTP code and, on success, log the user in or return an action token.
     * Folded from VerifyOtpAction (AuditLog removed).
     *
     * @throws SessionExpiredException when flow_token is present but stale
     * @throws ValidationException when OTP is invalid
     * @throws Exception when type is missing without a flow token
     */
    /** @return array<string, mixed> */
    public function verifyOtp(?string $identifier, string $code, ?OtpType $type): array
    {
        // Resolve identifier + type from flow token when provided.
        $flowToken = request()->input('flow_token');
        if ($flowToken) {
            $flowData = Cache::get("auth_flow_token:{$flowToken}");
            if (! $flowData) {
                throw new SessionExpiredException;
            }

            $resolvedType = OtpType::from($flowData['type']);
            if ($type && $type !== $resolvedType) {
                throw new SessionExpiredException;
            }

            $type = $resolvedType;
            $identifier = $flowData['identifier'];
        }

        if (! $type) {
            throw new Exception('OTP type is required when not using a flow token.');
        }

        // PASSWORD_RESET: generate action token, do not log in yet.
        if ($type === OtpType::PASSWORD_RESET) {
            $token = $this->otpService->verifyAndGenerateToken((string) $identifier, $code, $type);

            if ($flowToken) {
                Cache::forget("auth_flow_token:{$flowToken}");
            }

            return [
                'message' => __('api.auth.otp_verified'),
                'action_token' => $token,
                'type' => $type->value,
            ];
        }

        // Registration / Verification / Two-Factor: verify OTP then update user contact.
        $user = $this->transactional(function () use ($identifier, $code, $type) {
            $otpRecord = $this->otpService->verify((string) $identifier, $code, $type);

            if (! $otpRecord) {
                throw ValidationException::withMessages([
                    'code' => [__('api.auth.otp_invalid')],
                ]);
            }

            // Resolve user from OTP record, authenticated session, or contact lookup.
            $user = null;
            if ($otpRecord->user_id) {
                $user = User::find($otpRecord->user_id);
            }
            if (! $user && Auth::check()) {
                $user = Auth::user();
            }
            if (! $user) {
                $user = User::where('email', $identifier)->orWhere('phone', $identifier)->first();
            }

            // Mark the contact as verified (de-dup any unverified duplicate rows first).
            if ($user && $type->shouldVerifyContact()) {
                $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) && str_contains((string) $identifier, '.');
                $field = $isEmail ? 'email' : 'phone';
                $verifiedField = $field.'_verified_at';

                User::where($field, $identifier)
                    ->where('id', '!=', $user->id)
                    ->whereNull($verifiedField)
                    ->update([$field => null]);

                $user->$field = $identifier;
                $user->$verifiedField = now();
                $user->save();
            }

            // AuditLog removed.

            return $user;
        });

        if ($user) {
            $this->login($user);

            return [
                'message' => __('api.auth.otp_verified'),
                'user' => $user,
            ];
        }

        return ['message' => __('api.auth.otp_verified')];
    }

    /** @return array<string, mixed>|null */
    public function validateLoginStatus(User $user, string $identifier): ?array
    {
        $isUsingEmail = ($user->email === $identifier);
        $isVerified = $isUsingEmail ? (bool) $user->email_verified_at : (bool) $user->phone_verified_at;

        if (! $isVerified) {
            try {
                $this->otpService->generate($identifier, OtpType::REGISTRATION, $user);
            } catch (ThrottleException $e) {
                // OTP sent recently — skip resend
            }

            $flowToken = $this->otpService->generateFlowToken($identifier, OtpType::REGISTRATION, $user);

            return [
                'message' => __('api.auth.verification_required'),
                'verification_required' => true,
                'flow_token' => $flowToken,
                'masked_identifier' => $this->otpService->maskIdentifier($identifier),
            ];
        }

        if ($user->two_factor_confirmed_at) {
            $twoFactorType = $user->two_factor_type;

            // Resolve the identifier to send the 2FA OTP to.
            $isEmailOrPhone = in_array($twoFactorType, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true);
            $targetIdentifier = (string) ($isEmailOrPhone
                ? ($twoFactorType === TwoFactorType::EMAIL ? $user->email : $user->phone)
                : $user->email);

            if ($isEmailOrPhone && $targetIdentifier !== '') {
                $this->otpService->generate($targetIdentifier, OtpType::TWO_FACTOR, $user);
            }

            $flowToken = $this->otpService->generateFlowToken($targetIdentifier, OtpType::TWO_FACTOR, $user);

            return [
                'message' => __('api.auth.two_factor_required'),
                'two_factor_required' => true,
                'two_factor_type' => $twoFactorType,
                'flow_token' => $flowToken,
                'masked_identifier' => $this->otpService->maskIdentifier($targetIdentifier),
            ];
        }

        return null; // ready for login
    }

    /** @return array{message: string, user: User} */
    public function handleSuccessfulLogin(User $user): array
    {
        $this->login($user);

        // AuditLog removed — side-effect logging dropped per decoupling rules.

        return [
            'message' => __('api.auth.login_success'),
            'user' => $user,
        ];
    }

    public function logout(?User $user = null): void
    {
        // AuditLog removed.

        Auth::guard('web')->logout();

        if ($this->request->hasSession()) {
            $this->request->session()->invalidate();
            $this->request->session()->regenerateToken();
        }
    }

    public function logoutDevice(User $user, string $sessionId): bool
    {
        $currentSessionId = $this->getCurrentSessionId();

        if ($sessionId === $currentSessionId) {
            return false;
        }

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        return $deleted > 0;
    }

    public function logoutOtherDevices(User $user): void
    {
        $currentSessionId = $this->getCurrentSessionId();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    private const SESSION_LIMIT = 50;

    /**
     * @return array{sessions: Collection<int, Session>, total: int, has_more: bool}
     */
    public function getActiveSessions(User $user): array
    {
        $currentSessionId = $this->getCurrentSessionId();

        $sessions = Session::where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(self::SESSION_LIMIT + 1)
            ->get();

        $hasMore = $sessions->count() > self::SESSION_LIMIT;
        $limited = $sessions->take(self::SESSION_LIMIT)
            ->map(function ($session) use ($currentSessionId) {
                $session->is_current = ($session->id === $currentSessionId);

                return $session;
            });

        return [
            'sessions' => $limited,
            'total' => $limited->count(),
            'has_more' => $hasMore,
        ];
    }

    public function login(User $user): void
    {
        Auth::login($user);
        $this->regenerateSession();
    }

    /**
     * @throws ValidationException
     */
    public function verifyUserPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('api.auth.password_incorrect')],
            ]);
        }
    }

    public function regenerateSession(): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->regenerate();
        }
    }

    protected function getCurrentSessionId(): ?string
    {
        return $this->request->hasSession() ? $this->request->session()->getId() : null;
    }

    /**
     * Resolve a user by email or phone identifier.
     */
    public function findByIdentifier(?string $identifier): ?User
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }
}

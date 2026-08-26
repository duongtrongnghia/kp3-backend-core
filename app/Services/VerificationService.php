<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpType;
use App\Models\User;
use App\Traits\Transactional;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    use Transactional;

    public function __construct(
        protected OtpService $otpService,
    ) {}

    /**
     * Send verification OTP for secondary contact (email or phone).
     *
     * @throws ValidationException
     */
    public function sendVerification(User $user, string $identifier, string $type): void
    {
        $verifiedField = $type.'_verified_at';

        $existingUser = User::where($type, $identifier)
            ->where('id', '!=', $user->id)
            ->whereNotNull($verifiedField)
            ->first();

        if ($existingUser) {
            throw ValidationException::withMessages([
                'identifier' => __('api.verification.already_verified_another', ['type' => __('api.verification.'.$type)]),
            ]);
        }

        if ($type === 'email' && $user->email === $identifier && $user->email_verified_at) {
            throw ValidationException::withMessages([
                'identifier' => __('api.verification.self_verified'),
            ]);
        }
        if ($type === 'phone' && $user->phone === $identifier && $user->phone_verified_at) {
            throw ValidationException::withMessages([
                'identifier' => __('api.verification.self_verified'),
            ]);
        }

        $this->otpService->generate($identifier, OtpType::VERIFICATION, $user);
    }

    /**
     * Verify OTP and update user contact atomically.
     *
     * @throws ValidationException
     */
    public function verify(User $user, string $identifier, string $code): User
    {
        return $this->transactional(function () use ($user, $identifier, $code) {
            $isValid = $this->otpService->verify($identifier, $code, OtpType::VERIFICATION);

            if (! $isValid) {
                throw ValidationException::withMessages([
                    'code' => __('api.auth.otp_invalid'),
                ]);
            }

            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            $type = $isEmail ? 'email' : 'phone';
            $verifiedField = $type.'_verified_at';

            // Remove this contact from other unverified accounts
            User::where($type, $identifier)
                ->where('id', '!=', $user->id)
                ->whereNull($verifiedField)
                ->update([$type => null]);

            if ($type === 'email') {
                $user->email = $identifier;
                $user->email_verified_at = now();
            } else {
                $user->phone = $identifier;
                $user->phone_verified_at = now();
            }

            $user->save();

            // AuditLog removed.

            return $user;
        });
    }
}

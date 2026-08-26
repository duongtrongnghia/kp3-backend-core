<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpType;
use App\Enums\TwoFactorType;
use App\Models\Otp;
use App\Models\User;
use App\Traits\Transactional;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FALaravel\Facade as Google2FA;

class TwoFactorService
{
    use Transactional;

    public function __construct(
        protected OtpService $otpService,
    ) {}

    public function getQrCodeUrl(User $user): string
    {
        return Google2FA::getQRCodeUrl(
            (string) config('app.name'),
            (string) ($user->email ?? $user->phone),
            (string) $user->two_factor_secret,
        );
    }

    protected function verifyCodeOnly(User $user, string $code): bool
    {
        // Recovery codes are exactly 10 characters
        if (strlen($code) === 10 && $user->two_factor_recovery_codes) {
            $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

            return is_array($recoveryCodes) && in_array($code, $recoveryCodes, true);
        }

        if ($user->two_factor_type === TwoFactorType::APP && $user->two_factor_secret) {
            return (bool) Google2FA::verifyKey($user->two_factor_secret, $code);
        }

        if (in_array($user->two_factor_type, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true)) {
            $identifier = ($user->two_factor_type === TwoFactorType::EMAIL) ? $user->email : $user->phone;
            if ($identifier === null) {
                return false;
            }
            $otp = Otp::where('user_id', $user->id)
                ->where('identifier', $identifier)
                ->where('type', OtpType::TWO_FACTOR->value)
                ->where('expires_at', '>', now())
                ->first();

            return $otp !== null && Hash::check($code, $otp->code);
        }

        return false;
    }

    protected function consumeCode(User $user, string $code): void
    {
        if (strlen($code) === 10 && $user->two_factor_recovery_codes) {
            $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            if (is_array($recoveryCodes) && ($key = array_search($code, $recoveryCodes, true)) !== false) {
                unset($recoveryCodes[$key]);
                $user->forceFill([
                    'two_factor_recovery_codes' => count($recoveryCodes) > 0
                        ? encrypt(json_encode(array_values($recoveryCodes)))
                        : null,
                ])->save();
            }
        }

        if (in_array($user->two_factor_type, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true)) {
            $identifier = ($user->two_factor_type === TwoFactorType::EMAIL) ? $user->email : $user->phone;
            if ($identifier !== null) {
                $this->otpService->clear($identifier, OtpType::TWO_FACTOR);
            }
        }
    }

    /**
     * Atomic: Verify code + Confirm 2FA + Generate Recovery Codes.
     *
     * @return array<int, string> recovery codes
     *
     * @throws ValidationException
     */
    public function verifyAndConfirm(User $user, string $code): array
    {
        return $this->transactional(function () use ($user, $code) {
            if (! $this->verifyCodeOnly($user, $code)) {
                throw ValidationException::withMessages(['code' => __('api.auth.otp_invalid')]);
            }

            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
            $recoveryCodes = $this->generateRecoveryCodes($user);
            $this->consumeCode($user, $code);

            // AuditLog removed.

            return $recoveryCodes;
        });
    }

    /**
     * Atomic: Verify code + Disable 2FA.
     *
     * @throws ValidationException
     */
    public function verifyAndDisable(User $user, string $code): void
    {
        $this->transactional(function () use ($user, $code) {
            $twoFactorType = $user->two_factor_type;
            $identifier = ($twoFactorType === TwoFactorType::EMAIL) ? $user->email : $user->phone;

            if (! $this->verifyCodeOnly($user, $code)) {
                throw ValidationException::withMessages(['code' => __('api.auth.otp_invalid')]);
            }

            $user->forceFill([
                'two_factor_confirmed_at' => null,
                'two_factor_type' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
            ])->save();

            if (in_array($twoFactorType, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true) && $identifier !== null) {
                $this->otpService->clear($identifier, OtpType::TWO_FACTOR);
            }

            // AuditLog removed.
        });
    }

    /**
     * Setup 2FA — update type + generate secret/send OTP.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function setup(User $user, string $type): array
    {
        $secret = null;
        if ($type === TwoFactorType::APP->value) {
            $secret = Google2FA::generateSecretKey();
        }

        $fields = ['two_factor_type' => $type];
        if ($type === TwoFactorType::APP->value) {
            $fields['two_factor_secret'] = $secret;
        }
        $user->forceFill($fields)->save();

        $data = [];
        if ($type === TwoFactorType::APP->value) {
            $data['secret'] = $secret;
            $data['qr_code_url'] = $this->getQrCodeUrl($user);
        } else {
            $identifier = ($type === TwoFactorType::EMAIL->value) ? $user->email : $user->phone;
            if (! $identifier) {
                throw ValidationException::withMessages([
                    'type' => __('api.auth.no_contact_method', [
                        'type' => __("api.verification.{$type}"),
                    ]),
                ]);
            }
            $this->otpService->generate($identifier, OtpType::TWO_FACTOR, $user);
        }

        return $data;
    }

    /**
     * Verify 2FA code (App, OTP, or Recovery Code).
     * Used in login flow (verify endpoint).
     */
    public function verify(User $user, string $code): bool
    {
        if (strlen($code) === 10 && $user->two_factor_recovery_codes) {
            $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

            if (is_array($recoveryCodes) && ($key = array_search($code, $recoveryCodes, true)) !== false) {
                return $this->transactional(function () use ($user, $recoveryCodes, $key) {
                    unset($recoveryCodes[$key]);
                    $user->forceFill([
                        'two_factor_recovery_codes' => count($recoveryCodes) > 0
                            ? encrypt(json_encode(array_values($recoveryCodes)))
                            : null,
                    ])->save();

                    if (in_array($user->two_factor_type, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true)) {
                        $identifier = ($user->two_factor_type === TwoFactorType::EMAIL) ? $user->email : $user->phone;
                        if ($identifier !== null) {
                            $this->otpService->clear($identifier, OtpType::TWO_FACTOR);
                        }
                    }

                    return true;
                });
            }
        }

        if ($user->two_factor_type === TwoFactorType::APP && $user->two_factor_secret) {
            return (bool) Google2FA::verifyKey($user->two_factor_secret, $code);
        }

        if (in_array($user->two_factor_type, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true)) {
            $identifier = ($user->two_factor_type === TwoFactorType::EMAIL) ? $user->email : $user->phone;
            if ($identifier === null) {
                return false;
            }
            $otpRecord = $this->otpService->verify($identifier, $code, OtpType::TWO_FACTOR);

            return $otpRecord !== null;
        }

        return false;
    }

    /**
     * Verify a login 2FA challenge: load the user by id and check the code.
     * Returns the user on success, null on failure.
     */
    public function verifyChallenge(int $userId, string $code): ?User
    {
        $result = $this->transactional(function () use ($userId, $code): ?User {
            $user = User::findOrFail($userId);

            return $this->verify($user, $code) ? $user : null;
        });

        return $result instanceof User ? $result : null;
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(5));
        }

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ])->save();

        return $codes;
    }
}

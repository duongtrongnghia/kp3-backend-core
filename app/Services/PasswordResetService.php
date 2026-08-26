<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpType;
use App\Exceptions\SessionExpiredException;
use App\Models\User;
use App\Traits\Transactional;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetService
{
    use Transactional;

    public function __construct(
        protected OtpService $otpService,
    ) {}

    /**
     * Send password reset OTP and return a flow token.
     * Returns a fake success response when user not found (no user-existence leakage).
     *
     * @return array{flow_token: string, masked_identifier: string}
     */
    public function sendResetRequest(string $identifier): array
    {
        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user) {
            return [
                'flow_token' => Str::random(60),
                'masked_identifier' => $this->otpService->maskIdentifier($identifier),
            ];
        }

        $this->otpService->generate($identifier, OtpType::PASSWORD_RESET, $user);

        return [
            'flow_token' => $this->otpService->generateFlowToken($identifier, OtpType::PASSWORD_RESET, $user),
            'masked_identifier' => $this->otpService->maskIdentifier($identifier),
        ];
    }

    /**
     * Verify token and reset password atomically.
     *
     * @throws SessionExpiredException
     */
    public function reset(string $token, string $newPassword): void
    {
        $cacheKey = "auth_action_token:{$token}";
        $data = Cache::get($cacheKey);

        if (! $data || ($data['type'] ?? '') !== OtpType::PASSWORD_RESET->value) {
            throw new SessionExpiredException;
        }

        $identifier = $data['identifier'];
        $hashedPassword = Hash::make($newPassword);

        $this->transactional(function () use ($identifier, $cacheKey, $hashedPassword) {
            $user = User::where('email', $identifier)
                ->orWhere('phone', $identifier)
                ->first();

            if (! $user) {
                throw new SessionExpiredException;
            }

            $user->password = $hashedPassword;
            $user->save();

            Cache::forget($cacheKey);

            // AuditLog removed.
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpType;
use App\Exceptions\ThrottleException;
use App\Models\Otp;
use App\Models\User;
use App\Notifications\SendEmailVerificationNotification;
use App\Notifications\SendOtpNotification;
use App\Notifications\SendPasswordResetNotification;
use App\Notifications\SendSecondaryContactNotification;
use App\Traits\Transactional;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtpService
{
    use Transactional;

    /**
     * Generate, store, and send an OTP code.
     *
     * @throws ThrottleException when rate limit exceeded
     */
    public function generate(string $identifier, OtpType $type, ?User $user = null): string
    {
        $this->checkRateLimit($identifier, $type);

        $code = (string) random_int(100000, 999999);
        $hashedCode = Hash::make($code);

        return $this->transactional(function () use ($identifier, $code, $hashedCode, $type, $user) {
            Otp::where('identifier', $identifier)->where('type', $type->value)->delete();

            Otp::create([
                'user_id' => $user?->id,
                'identifier' => $identifier,
                'code' => $hashedCode,
                'type' => $type->value,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]);

            $this->sendNotification($identifier, $code, $type, $user);

            return $code;
        });
    }

    /**
     * @throws ThrottleException
     */
    protected function checkRateLimit(string $identifier, OtpType $type): void
    {
        $secondsRemaining = $this->getRemainingCooldown($identifier, $type);

        if ($secondsRemaining > 0) {
            throw new ThrottleException(
                $secondsRemaining,
                __('api.auth.otp_throttle', ['seconds' => (int) $secondsRemaining]),
            );
        }
    }

    public function getRemainingCooldown(string $identifier, OtpType $type): int
    {
        $latestOtp = Otp::where('identifier', $identifier)
            ->where('type', $type->value)
            ->latest('created_at')
            ->first();

        if (! $latestOtp) {
            return 0;
        }

        $cooldown = 60;
        $elapsed = (float) $latestOtp->created_at->diffInSeconds(Carbon::now());
        $remaining = ceil($cooldown - $elapsed);

        return $remaining > 0 ? (int) $remaining : 0;
    }

    protected function sendNotification(string $identifier, string $code, OtpType $type = OtpType::REGISTRATION, ?User $user = null): void
    {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) && str_contains($identifier, '.');

        $url = null;
        if ($isEmail) {
            $url = $this->buildVerifyLinkToken($identifier, $code, $type);
        }

        $notification = $this->resolveNotification($code, $isEmail ? 'mail' : 'sms', $url, $type);

        $user = $user ?: auth()->user() ?: User::where($isEmail ? 'email' : 'phone', $identifier)->first();

        if ($user) {
            $notification->locale($user->preferredLocale());
        }

        if ($isEmail) {
            NotificationFacade::route('mail', $identifier)->notify($notification);
        } else {
            NotificationFacade::route('sms', $identifier)->notify($notification);
        }
    }

    protected function resolveNotification(string $code, string $channel, ?string $url, OtpType $type): Notification
    {
        return match ($type) {
            OtpType::PASSWORD_RESET => new SendPasswordResetNotification($code, $channel, $url),
            OtpType::REGISTRATION => new SendEmailVerificationNotification($code, $channel, $url),
            OtpType::VERIFICATION => new SendSecondaryContactNotification($code, $channel, $url),
            OtpType::TWO_FACTOR => new SendOtpNotification($code, $channel, $url),
        };
    }

    protected function buildVerifyLinkToken(string $identifier, string $code, OtpType $type): string
    {
        $token = Str::random(64);
        $ttlMinutes = $type === OtpType::PASSWORD_RESET ? 15 : 10;

        Cache::put("auth_verify_link:{$token}", [
            'identifier' => $identifier,
            'code' => $code,
            'type' => $type->value,
        ], now()->addMinutes($ttlMinutes));

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        if ($type === OtpType::PASSWORD_RESET) {
            return "{$frontendUrl}/reset-password?link_token={$token}";
        }

        return "{$frontendUrl}/verify-link?token={$token}";
    }

    public function verify(string $identifier, string $code, OtpType $type): ?Otp
    {
        return $this->transactional(function () use ($identifier, $code, $type) {
            $otp = Otp::where('identifier', $identifier)
                ->where('type', $type->value)
                ->where('expires_at', '>', Carbon::now())
                ->lockForUpdate()
                ->first();

            if ($otp && Hash::check($code, $otp->code)) {
                Otp::where('identifier', $identifier)
                    ->where('type', $type->value)
                    ->delete();

                return $otp;
            }

            return null;
        });
    }

    /**
     * @throws ValidationException
     */
    public function verifyAndGenerateToken(string $identifier, string $code, OtpType $type): string
    {
        $otp = $this->verify($identifier, $code, $type);

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => [__('api.auth.otp_invalid')],
            ]);
        }

        $token = Str::random(60);
        $cacheData = [
            'identifier' => $identifier,
            'type' => $type->value,
            'user_id' => $otp->user_id,
            'verified_at' => now()->toDateTimeString(),
        ];

        Cache::put("auth_action_token:{$token}", $cacheData, now()->addMinutes(15));

        return $token;
    }

    public function generateFlowToken(string $identifier, OtpType $type, ?User $user = null): string
    {
        $flowToken = Str::random(60);

        Cache::put("auth_flow_token:{$flowToken}", [
            'identifier' => $identifier,
            'type' => $type->value,
            'user_id' => $user?->id,
        ], now()->addMinutes(15));

        return $flowToken;
    }

    public function maskIdentifier(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            [$user, $domain] = explode('@', $identifier);
            $maskedUser = substr($user, 0, 2).str_repeat('*', max(0, strlen($user) - 2));

            return $maskedUser.'@'.$domain;
        }

        return substr($identifier, 0, 3).str_repeat('*', max(0, strlen($identifier) - 6)).substr($identifier, -3);
    }

    public function clear(string $identifier, OtpType $type): void
    {
        Otp::where('identifier', $identifier)
            ->where('type', $type->value)
            ->delete();
    }
}

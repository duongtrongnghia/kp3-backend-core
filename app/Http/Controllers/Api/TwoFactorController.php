<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OtpType;
use App\Enums\TwoFactorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\Confirm2faRequest;
use App\Http\Requests\Auth\Setup2faRequest;
use App\Http\Requests\Auth\Verify2faRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Services\TwoFactorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TwoFactorService $twoFactorService,
        protected AuthService $authService,
        protected OtpService $otpService,
    ) {}

    /**
     * Verify 2FA code during login flow (flow_token or two_factor_token cookie).
     */
    public function verify(Verify2faRequest $request): JsonResponse
    {
        $flowToken = $request->flow_token ?? $request->two_factor_token;
        $cacheKey = "auth_flow_token:{$flowToken}";
        $data = Cache::get($cacheKey);

        if (! $data || ($data['type'] ?? '') !== OtpType::TWO_FACTOR->value) {
            return $this->error(__('api.auth.2fa_token_expired'), 422);
        }

        $user = $this->twoFactorService->verifyChallenge((int) $data['user_id'], (string) $request->code);

        if (! $user) {
            return $this->error(__('api.auth.2fa_failed'), 422);
        }

        Cache::forget($cacheKey);
        $result = $this->authService->handleSuccessfulLogin($user);

        return $this->resource(new UserResource($user), $result['message'])
            ->withCookie(cookie()->forget('2fa_token'));
    }

    /**
     * Setup 2FA — update type, generate secret or send OTP.
     */
    public function setup(Setup2faRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error(__('api.auth.password_incorrect'), 422);
        }

        $data = $this->twoFactorService->setup($user, $request->type);

        if (isset($data['qr_code_url'])) {
            return $this->success($data, __('api.auth.2fa_setup_success'));
        }

        return $this->success(array_merge($data, ['retry_after' => 60]), __('api.auth.2fa_setup_success'));
    }

    /**
     * Confirm 2FA setup — verify code, mark confirmed, generate recovery codes.
     */
    public function confirm(Confirm2faRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $recoveryCodes = $this->twoFactorService->verifyAndConfirm($user, $request->code);

        return $this->resource(
            new UserResource($user->fresh() ?? $user),
            __('api.auth.2fa_enabled'),
            200,
            ['recovery_codes' => $recoveryCodes]
        );
    }

    /**
     * Send OTP for disabling 2FA (EMAIL/PHONE types only).
     */
    public function disableSend(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $user->two_factor_confirmed_at) {
            return $this->error(__('api.auth.2fa_not_enabled'), 422);
        }

        if (in_array($user->two_factor_type, [TwoFactorType::EMAIL, TwoFactorType::PHONE], true)) {
            $identifier = $user->two_factor_type === TwoFactorType::EMAIL
                ? $user->email
                : $user->phone;

            $this->otpService->generate((string) $identifier, OtpType::TWO_FACTOR, $user);

            return $this->success(['retry_after' => 60], __('api.auth.2fa_disabled_sent'));
        }

        return $this->success(null, __('api.auth.2fa_app_instruction'));
    }

    /**
     * Confirm disable 2FA — verify code, clear 2FA fields.
     */
    public function disableConfirm(Confirm2faRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $user->two_factor_confirmed_at) {
            return $this->error(__('api.auth.2fa_not_enabled'), 422);
        }

        $this->twoFactorService->verifyAndDisable($user, $request->code);

        return $this->resource(new UserResource($user->fresh() ?? $user), __('api.auth.2fa_disabled_success'));
    }
}

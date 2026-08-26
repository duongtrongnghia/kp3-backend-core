<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\Auth\LoginData;
use App\DTOs\Auth\RegisterData;
use App\Enums\OtpType;
use App\Exceptions\SessionExpiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\VerifyPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Traits\ApiResponse;
use App\Traits\Transactional;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Handles registration, login, OTP verify, logout, verify-by-token.
 *
 * Actions folded in (no app/Actions/ dir):
 *   RegisterUserAction → AuthService::register()
 *   LoginUserAction    → AuthService::attemptLogin()
 *   VerifyOtpAction    → AuthService::verifyOtp()
 */
class AuthController extends Controller
{
    use ApiResponse, Transactional;

    public function __construct(
        protected AuthService $authService,
        protected OtpService $otpService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->transactional(
            fn () => $this->authService->register(RegisterData::fromArray($request->validated()))
        );

        $identifier = (string) ($user->email ?? $user->phone);

        return $this->success([
            'identifier' => $identifier,
            'flow_token' => $this->otpService->generateFlowToken($identifier, OtpType::REGISTRATION, $user),
            'masked_identifier' => $this->otpService->maskIdentifier($identifier),
            'message' => __('api.auth.registration_success'),
        ], status: 201);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $type = $request->type ? OtpType::from($request->type) : null;
        $result = $this->authService->verifyOtp($request->identifier, $request->code, $type);

        if (isset($result['user'])) {
            return $this->resource(
                new UserResource($result['user']),
                $result['message'] ?? __('api.auth.otp_verified')
            );
        }

        return $this->success($result, $result['message'] ?? __('api.auth.otp_verified'));
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $identifier = $request->identifier;
        $type = $request->type ? OtpType::from($request->type) : null;

        // Resolve identifier + type from flow_token when provided.
        if ($request->flow_token) {
            $flowData = Cache::get("auth_flow_token:{$request->flow_token}");
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
            return $this->error('OTP type is required.', 422);
        }

        $user = $this->authService->findByIdentifier($identifier);
        $this->otpService->generate((string) $identifier, $type, $user);

        return $this->success(['retry_after' => 60], __('api.verification.sent'));
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $loginData = LoginData::fromArray($request->validated(), $request->ip(), $request->userAgent());
        $user = $this->authService->attemptLogin($loginData);

        if ($status = $this->authService->validateLoginStatus($user, $loginData->identifier)) {
            $message = $status['message'] ?? '';
            unset($status['message']);

            if (! empty($status['two_factor_required']) && ! empty($status['two_factor_token'])) {
                $token = $status['two_factor_token'];
                $cookie = cookie('2fa_token', $token, 10, null, null, true, true);
                unset($status['two_factor_token']);

                return $this->success($status, $message)->withCookie($cookie);
            }

            return $this->success($status, $message);
        }

        $result = $this->authService->handleSuccessfulLogin($user);

        return $this->resource(new UserResource($result['user']), $result['message']);
    }

    public function verifyPassword(VerifyPasswordRequest $request): JsonResponse
    {
        $this->authService->verifyUserPassword($this->currentUser($request), $request->password);

        return $this->success(null, __('api.auth.password_correct'));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($this->currentUser($request));

        return $this->success(null, __('api.auth.logout_success'));
    }

    /**
     * Verify a one-time link token from email.
     *
     * Token is an opaque key stored in cache — no sensitive data in URL.
     * Handles all OTP types: registration, verification, two_factor, password_reset.
     * PASSWORD_RESET re-uses existing action_token on repeat clicks (idempotent).
     */
    public function verifyByToken(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');

        if (! $token) {
            return $this->error(__('api.auth.invalid_token'), 422);
        }

        $cacheKey = "auth_verify_link:{$token}";
        $data = Cache::get($cacheKey);

        if (! $data) {
            return $this->error(__('api.auth.link_expired'), 422);
        }

        $type = OtpType::from($data['type']);

        // Return cached action_token on re-click without re-consuming the OTP.
        if ($type === OtpType::PASSWORD_RESET && isset($data['action_token'])) {
            return $this->success(['action_token' => $data['action_token']], __('api.auth.otp_verified'));
        }

        // One-time use for login-related types.
        if ($type !== OtpType::PASSWORD_RESET) {
            Cache::forget($cacheKey);
        }

        try {
            $result = $this->authService->verifyOtp($data['identifier'], $data['code'], $type);

            if (isset($result['user'])) {
                return $this->resource(
                    new UserResource($result['user']),
                    $result['message'] ?? __('api.auth.otp_verified')
                );
            }

            // Cache action_token so repeat clicks return it without re-processing.
            if (isset($result['action_token'])) {
                Cache::put($cacheKey, array_merge($data, ['action_token' => $result['action_token']]), now()->addMinutes(15));
            }

            return $this->success($result, $result['message'] ?? __('api.auth.otp_verified'));
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

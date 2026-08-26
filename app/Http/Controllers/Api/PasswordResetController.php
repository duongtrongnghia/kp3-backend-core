<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OtpType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PasswordResetService $passwordResetService,
        protected AuthService $authService,
    ) {}

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $result = $this->passwordResetService->sendResetRequest($request->identifier);

        return $this->success([
            'flow_token' => $result['flow_token'],
            'masked_identifier' => $result['masked_identifier'],
            'retry_after' => 60,
        ], __('api.auth.forgot_password_sent'));
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->reset($request->token, $request->password);

        return $this->success(null, __('api.auth.password_reset_success'));
    }

    /**
     * Handle the magic link click from the password-reset email.
     * Validates the OTP embedded in the link → generates an action_token
     * → redirects browser or returns JSON.
     */
    public function redirect(Request $request): mixed
    {
        $identifier = (string) $request->query('identifier', '');
        $code = (string) $request->query('token', '');

        try {
            $result = $this->authService->verifyOtp($identifier, $code, OtpType::PASSWORD_RESET);
            $actionToken = $result['action_token'];

            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'data' => ['actionToken' => $actionToken]]);
            }

            $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

            return redirect()->away($frontendUrl.'/reset-password?token='.urlencode($actionToken));
        } catch (Exception $e) {
            if ($request->expectsJson()) {
                return $this->error($e->getMessage(), 422);
            }

            $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

            return redirect()->away($frontendUrl.'/forgot-password');
        }
    }
}

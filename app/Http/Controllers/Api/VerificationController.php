<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendVerificationRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\VerificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class VerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected VerificationService $verificationService,
    ) {}

    public function send(SendVerificationRequest $request): JsonResponse
    {
        $this->verificationService->sendVerification(
            $this->currentUser($request),
            $request->identifier,
            $request->type
        );

        return $this->success(null, __('api.verification.sent'));
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->verificationService->verify(
            $this->currentUser($request),
            $request->identifier,
            $request->code
        );

        return $this->resource(new UserResource($user), __('api.verification.success'));
    }
}

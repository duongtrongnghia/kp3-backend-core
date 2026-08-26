<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class SessionExpiredException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            message: __('api.auth.invalid_link_or_token'),
            statusCode: 422,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $this->statusCode,
            'message' => $this->getMessage(),
            'error_code' => 'SESSION_EXPIRED',
        ], $this->statusCode);
    }
}

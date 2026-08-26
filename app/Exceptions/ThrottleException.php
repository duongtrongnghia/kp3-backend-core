<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class ThrottleException extends ApiException
{
    protected int $seconds;

    public function __construct(int $seconds, string $message = '', mixed $errors = null)
    {
        $this->seconds = $seconds;
        $message = $message ?: __('api.auth.rate_limited', ['seconds' => $seconds]);

        if ($errors === null) {
            $errors = ['throttle' => [$message]];
        }

        parent::__construct($message, 422, $errors);
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $this->statusCode,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
            'retry_after' => $this->seconds,
        ], $this->statusCode);
    }
}

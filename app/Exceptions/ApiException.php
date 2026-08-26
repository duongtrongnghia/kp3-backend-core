<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

abstract class ApiException extends Exception
{
    protected int $statusCode = 400;

    protected mixed $errors = null;

    public function __construct(string $message = '', int $statusCode = 400, mixed $errors = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errors = $errors;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): mixed
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $this->statusCode,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->statusCode);
    }
}

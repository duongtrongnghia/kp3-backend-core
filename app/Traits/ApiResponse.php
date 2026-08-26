<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

trait ApiResponse
{
    protected function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(?string $message = null, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    /** @param array<string, mixed> $additional */
    protected function resource(JsonResource $resource, ?string $message = null, int $status = 200, array $additional = []): JsonResponse
    {
        return $resource->additional(array_merge([
            'success' => true,
            'status' => $status,
            'message' => $message,
        ], $additional))->response()->setStatusCode($status);
    }

    /** @param array<string, mixed> $additional */
    protected function collection(mixed $collection, ?string $message = null, int $status = 200, array $additional = []): JsonResponse
    {
        return $collection->additional(array_merge([
            'success' => true,
            'status' => $status,
            'message' => $message,
        ], $additional))->response()->setStatusCode($status);
    }
}

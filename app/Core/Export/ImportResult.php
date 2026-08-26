<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * Result of an import operation.
 */
class ImportResult
{
    /**
     * @param  array<int, array{row: int|null, message: string, field?: string|null}>  $errors
     */
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {}

    /**
     * @return array{success: int, failed: int, errors: array<int, array{row: int|null, message: string, field?: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}

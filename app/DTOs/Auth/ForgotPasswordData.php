<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

class ForgotPasswordData
{
    public function __construct(
        public readonly string $identifier,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(identifier: $data['identifier']);
    }
}

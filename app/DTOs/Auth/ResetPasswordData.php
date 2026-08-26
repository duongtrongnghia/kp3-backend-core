<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

class ResetPasswordData
{
    public function __construct(
        public readonly string $token,
        public readonly string $password,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            token: $data['token'],
            password: $data['password'],
        );
    }
}

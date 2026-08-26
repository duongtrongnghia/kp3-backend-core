<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

class RegisterData
{
    public function __construct(
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $password,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            password: $data['password'],
        );
    }
}

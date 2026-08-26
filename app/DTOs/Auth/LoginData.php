<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

class LoginData
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $password,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $ipAddress = null, ?string $userAgent = null): self
    {
        return new self(
            identifier: $data['identifier'],
            password: $data['password'],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }
}

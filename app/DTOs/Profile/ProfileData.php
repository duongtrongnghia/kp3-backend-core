<?php

declare(strict_types=1);

namespace App\DTOs\Profile;

class ProfileData
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $dob = null,
        public readonly ?string $gender = null,
        public readonly ?string $addressStreet = null,
        public readonly ?string $addressCommune = null,
        public readonly ?string $addressProvince = null,
        public readonly ?string $timezone = null,
        public readonly ?string $language = null,
        public readonly ?string $dateFormat = null,
        public readonly ?bool $deleteAvatar = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            dob: $data['dob'] ?? null,
            gender: $data['gender'] ?? null,
            addressStreet: $data['address_street'] ?? null,
            addressCommune: $data['address_commune'] ?? null,
            addressProvince: $data['address_province'] ?? null,
            timezone: $data['timezone'] ?? null,
            language: $data['language'] ?? null,
            dateFormat: $data['date_format'] ?? null,
            deleteAvatar: $data['delete_avatar'] ?? false,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'dob' => $this->dob,
            'gender' => $this->gender,
            'address_street' => $this->addressStreet,
            'address_commune' => $this->addressCommune,
            'address_province' => $this->addressProvince,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'date_format' => $this->dateFormat,
        ];

        if ($this->email !== null) {
            $data['email'] = $this->email;
        }

        if ($this->phone !== null) {
            $data['phone'] = $this->phone;
        }

        return $data;
    }
}

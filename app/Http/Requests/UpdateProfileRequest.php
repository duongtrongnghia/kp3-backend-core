<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', new Enum(Gender::class)],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_commune' => ['nullable', 'string', 'max:255'],
            'address_province' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable'], // file upload handled manually in ProfileService
            'timezone' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'in:en,vi'],
            'date_format' => ['nullable', 'string', 'in:d/m/Y,Y/m/d'],
            'delete_avatar' => ['nullable', 'boolean'],
        ];
    }
}

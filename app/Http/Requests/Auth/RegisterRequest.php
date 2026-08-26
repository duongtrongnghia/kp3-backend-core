<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\UniqueVerifiedContact;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required_without:phone', 'nullable', 'email', new UniqueVerifiedContact('email')],
            'phone' => ['required_without:email', 'nullable', 'string', new UniqueVerifiedContact('phone')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}

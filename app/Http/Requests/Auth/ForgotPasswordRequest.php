<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\EmailOrPhone;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', new EmailOrPhone],
        ];
    }
}

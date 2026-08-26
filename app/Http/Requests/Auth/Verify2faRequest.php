<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class Verify2faRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'flow_token' => ['nullable', 'string'],
        ];
    }

    /** Merge the HttpOnly 2fa_token cookie as two_factor_token for the controller to read. */
    protected function prepareForValidation(): void
    {
        if ($this->cookie('2fa_token')) {
            $this->merge(['two_factor_token' => $this->cookie('2fa_token')]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\TwoFactorType;
use App\Rules\EmailOrPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SendVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(TwoFactorType::class)],
            'identifier' => ['required', 'string', new EmailOrPhone],
        ];
    }
}

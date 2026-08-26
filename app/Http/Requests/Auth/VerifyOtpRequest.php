<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\OtpType;
use App\Rules\EmailOrPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identifier' => ['required_without:flow_token', 'nullable', 'string', new EmailOrPhone],
            'flow_token' => ['nullable', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:6'],
            'type' => ['required_without:flow_token', 'nullable', new Enum(OtpType::class)],
        ];
    }
}

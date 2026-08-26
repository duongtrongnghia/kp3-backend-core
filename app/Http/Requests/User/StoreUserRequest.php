<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\Gender;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Permission module decoupled:
 *   PermissionService::isSuperAdmin() → $caller->isSuperAdmin()
 *   theme_active_roles()              → UserRole enum values
 *
 * Identity PII fields (national_id, dob, gender, address_*) are restricted
 * to admin (isSuperAdmin) callers — mirrors UserResource read gate.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            'role' => ['required', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
        ];

        $caller = $this->user();
        if (! $caller || ! $caller->isSuperAdmin()) {
            return $base;
        }

        // Super-admin: allow identity PII fields.
        return $base + [
            'national_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dob' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', new Enum(Gender::class)],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_commune' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address_province' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (empty($this->input('email')) && empty($this->input('phone'))) {
                $v->errors()->add('email', __('users.validation.email_or_phone_required'));
            }
        });
    }

    /**
     * Strip PII from validated data for non-admin callers (belt-and-braces).
     *
     * @param  string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        $caller = $this->user();
        if ($caller && $caller->isSuperAdmin()) {
            return $data;
        }

        foreach (['national_id', 'dob', 'gender', 'address_street', 'address_commune', 'address_province', 'country_code'] as $field) {
            unset($data[$field]);
        }

        return $data;
    }
}

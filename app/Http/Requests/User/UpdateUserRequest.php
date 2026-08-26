<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Permission module decoupled:
 *   PermissionService::isSuperAdmin() → $caller->isSuperAdmin()
 *   theme_active_roles()              → UserRole enum values
 *
 * Role uniqueness: allow keeping the user's current role even if it is no
 * longer in the UserRole enum (legacy data safety).
 *
 * Identity PII fields (national_id, dob, gender, address_*) restricted to admin.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $allowed = array_column(UserRole::cases(), 'value');

        // Preserve the current role value even if no longer in enum (legacy rows).
        $current = $this->resolveCurrentRole();
        if ($current !== null && ! in_array($current, $allowed, true)) {
            $allowed[] = $current;
        }

        $base = [
            'role' => ['sometimes', 'string', Rule::in($allowed)],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['sometimes', 'nullable', 'string', Rule::unique('users', 'phone')->ignore($this->route('user'))],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'language' => ['sometimes', 'nullable', 'string', 'in:en,vi'],
            'date_format' => ['sometimes', 'nullable', 'string', 'in:d/m/Y,Y/m/d'],
        ];

        $caller = $this->user();
        if (! $caller || ! $caller->isSuperAdmin()) {
            return $base;
        }

        // Super-admin: allow identity PII fields.
        return $base + [
            'dob' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', new Enum(Gender::class)],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_commune' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address_province' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'national_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                Rule::unique('users', 'national_id_hash')
                    ->where(fn ($q) => $q->where(
                        'national_id_hash',
                        hash_hmac('sha256', (string) $this->input('national_id'), (string) config('app.key'))
                    ))
                    ->ignore($this->route('user')),
            ],
        ];
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

    private function resolveCurrentRole(): ?string
    {
        $user = $this->route('user');

        if ($user instanceof User) {
            return $user->role;
        }

        if (is_numeric($user)) {
            return User::query()->whereKey($user)->value('role');
        }

        return null;
    }
}

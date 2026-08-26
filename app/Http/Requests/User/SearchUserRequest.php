<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class SearchUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Role values are validated against the UserRole enum (no DB roles table).
        $validRoles = array_column(UserRole::cases(), 'value');

        return [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'array', 'min:1'],
            'role.*' => ['string', 'in:'.implode(',', $validRoles)],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:active,inactive,locked'],
            '_2fa' => ['nullable', 'string', 'in:without'],
            'last_login_at' => ['nullable', 'string', 'in:inactive_30d'],
            // show_deleted: exclude (default) | only (trashed only) | with (both)
            'show_deleted' => ['nullable', 'string', 'in:exclude,only,with'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'created_at' => ['nullable', 'array', 'size:2'],
            'created_at.*' => ['numeric'],
        ];
    }
}

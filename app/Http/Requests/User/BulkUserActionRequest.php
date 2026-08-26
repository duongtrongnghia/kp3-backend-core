<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared base for bulk admin actions requiring a user_ids array.
 * Subclasses / controllers add their own scenario rules (role, reason, etc).
 */
class BulkUserActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}

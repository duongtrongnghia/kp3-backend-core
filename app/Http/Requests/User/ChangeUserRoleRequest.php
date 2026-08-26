<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Validate against UserRole enum values — no roles DB table needed.
        return [
            'role' => ['required', 'string', Rule::in(array_column(UserRole::cases(), 'value'))],
        ];
    }
}

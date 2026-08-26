<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /users/invite.
 *
 * Gate 1 — route middleware 'role:admin' (enforced before this request)
 * Gate 2 — email uniqueness across users + pending invitations
 * Gate 3 — invited role level must be strictly below the caller's level
 *           (admin/isSuperAdmin bypass: skips level check entirely)
 *
 * Permission module decoupled:
 *   PermissionService::isSuperAdmin() → $caller->isSuperAdmin()
 *   Role::query()->where('slug')->value('level') → UserRole::tryFrom()->level()
 *   theme_active_roles() → UserRole enum values
 */
class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by route middleware role:admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('user_invitations', 'email')->where('status', 'pending'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => [
                'required',
                'string',
                Rule::in(array_column(UserRole::cases(), 'value')),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $caller = $this->user();
                    if (! $caller) {
                        return;
                    }
                    // Admin (isSuperAdmin) bypasses level check.
                    if ($caller->isSuperAdmin()) {
                        return;
                    }
                    // Caller cannot invite someone to a role >= their own level.
                    $targetLevel = UserRole::tryFrom((string) $value)?->level() ?? 0;
                    if ($targetLevel >= $caller->getRoleLevel()) {
                        $fail(__('users.errors.target_higher_role'));
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => __('api.invitation.email_conflict'),
        ];
    }
}

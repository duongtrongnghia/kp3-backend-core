<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Storage;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * Permission module decoupled:
     *   PermissionService::isSuperAdmin() → $caller->isSuperAdmin() (role === 'admin')
     *
     * Identity PII (national_id, dob, gender, address_*) surface only when the
     * requesting user isSuperAdmin(). Non-admin callers get MissingValue — keys
     * drop from JSON entirely so FE can gate on `'national_id' in user`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isSuper = $this->callerIsSuperAdmin($request);
        $piiOrMissing = fn (mixed $v): mixed => $isSuper ? $v : new MissingValue;

        return [
            'id' => $this->id,
            'name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,

            // PII — only for admin callers
            'dob' => $piiOrMissing($this->dob),
            'gender' => $piiOrMissing($this->gender),
            'address_street' => $piiOrMissing($this->address_street),
            'address_commune' => $piiOrMissing($this->address_commune),
            'address_province' => $piiOrMissing($this->address_province),
            'country_code' => $piiOrMissing($this->country_code),

            'avatar' => $this->avatar
                ? (filter_var($this->avatar, FILTER_VALIDATE_URL)
                    ? $this->avatar                                    // social provider full URL
                    : Storage::disk('public')->url($this->avatar))     // local storage path
                : null,

            'timezone' => $this->timezone,
            'language' => $this->language,
            'date_format' => $this->date_format,
            'appearance_settings' => $this->appearance_settings,

            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,

            'is_email_verified' => (bool) $this->email_verified_at,
            'is_phone_verified' => (bool) $this->phone_verified_at,
            'is_2fa_enabled' => (bool) $this->two_factor_confirmed_at,
            'two_factor_type' => $this->two_factor_type,

            'social_accounts' => $this->whenLoaded('socialAccounts', fn () => $this->socialAccounts->pluck('provider')),

            'created_at' => $this->created_at->toIso8601String(),

            // Admin lifecycle fields
            'status' => $this->status?->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->locked_by,
            'locked_by_name' => $this->whenLoaded('lockedBy', fn () => $this->lockedBy?->full_name),
            'lock_reason' => $this->lock_reason,
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'deactivated_by' => $this->deactivated_by,
            'deactivated_by_name' => $this->whenLoaded('deactivatedBy', fn () => $this->deactivatedBy?->full_name),
            'deactivation_reason' => $this->deactivation_reason,

            'role_level' => $this->resource instanceof User
                ? $this->resource->getRoleLevel()
                : 0,

            // Soft-delete state for trashed-row badge in admin list
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            // Identity — admin only (MissingValue → key absent from JSON)
            'national_id' => $this->whenSuperAdminCaller($request),

            // Invitation state (only present when relation is eager-loaded)
            'has_pending_invitation' => $this->when(
                $this->relationLoaded('pendingInvitation'),
                fn () => $this->pendingInvitation !== null,
            ),
            'invitation_expires_at' => $this->whenLoaded(
                'pendingInvitation',
                fn () => $this->pendingInvitation?->expires_at?->toIso8601String(),
            ),
        ];
    }

    /**
     * Permission module decoupled: use User::isSuperAdmin() (role === 'admin').
     */
    private function callerIsSuperAdmin(Request $request): bool
    {
        $caller = $request->user();

        return $caller instanceof User && $caller->isSuperAdmin();
    }

    /** Returns national_id for admin callers; MissingValue (key absent) for others. */
    private function whenSuperAdminCaller(Request $request): mixed
    {
        if (! $this->callerIsSuperAdmin($request)) {
            return new MissingValue;
        }

        return $this->resource instanceof User
            ? $this->resource->national_id
            : null;
    }
}

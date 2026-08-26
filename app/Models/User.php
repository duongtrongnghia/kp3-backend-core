<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Enums\TwoFactorType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string $password
 * @property string $role
 * @property UserStatus|null $status
 * @property Carbon|null $dob
 * @property Gender|null $gender
 * @property string|null $avatar
 * @property string|null $address_street
 * @property string|null $address_commune
 * @property string|null $address_province
 * @property string|null $country_code
 * @property string $timezone
 * @property string|null $language
 * @property string $date_format
 * @property array<string, mixed>|null $appearance_settings
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property TwoFactorType|null $two_factor_type
 * @property string|null $national_id_encrypted
 * @property string|null $national_id_hash
 * @property Carbon|null $last_login_at
 * @property Carbon|null $locked_at
 * @property int|null $locked_by
 * @property string|null $lock_reason
 * @property Carbon|null $deactivated_at
 * @property int|null $deactivated_by
 * @property string|null $deactivation_reason
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string  $full_name
 * @property-read string|null $national_id
 * @property-read User|null $lockedBy
 * @property-read User|null $deactivatedBy
 * @property-read UserInvitation|null $pendingInvitation
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read Collection<int, Otp> $otps
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'dob',
        'gender',
        'address_street',
        'address_commune',
        'address_province',
        'country_code',
        'avatar',
        'timezone',
        'language',
        'date_format',
        'appearance_settings',
        'email',
        'phone',
        'password',
        'two_factor_type',
        'status',
        'last_login_at',
        'locked_at',
        'locked_by',
        'lock_reason',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
        'national_id_encrypted',
        'national_id_hash',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'national_id_encrypted',
        'national_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'gender' => Gender::class,
            'two_factor_type' => TwoFactorType::class,
            'appearance_settings' => 'array',
            'national_id_encrypted' => 'encrypted',
        ];
    }

    // ─── Virtual national_id field ────────────────────────────────────────────

    public function getNationalIdAttribute(): ?string
    {
        return $this->national_id_encrypted;
    }

    public function setNationalIdAttribute(?string $value): void
    {
        $value = $value !== null ? trim($value) : null;
        $value = $value === '' ? null : $value;
        $this->national_id_encrypted = $value;
        $this->attributes['national_id_hash'] = $value === null
            ? null
            : hash_hmac('sha256', $value, config('app.key'));
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /** @return HasOne<UserInvitation, $this> */
    public function pendingInvitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class, 'email', 'email')
            ->where('status', 'pending')
            ->latestOfMany();
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<Otp, $this> */
    public function otps(): HasMany
    {
        return $this->hasMany(Otp::class);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: (
            ($this->email ? explode('@', $this->email)[0] : $this->phone) ?? 'Guest'
        );
    }

    // ─── Role helpers (replaces Modules\Permission coupling) ─────────────────

    /**
     * Returns true when this user has the ADMIN role.
     * Replaces PermissionService::isSuperAdmin() throughout ported code.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::ADMIN->value;
    }

    /**
     * Resolve privilege level from UserRole enum.
     * Replaces the DB-backed Modules\Permission\Models\Role level lookup.
     * ADMIN => 100, CUSTOMER => 10, unknown/null => 0.
     */
    public function getRoleLevel(): int
    {
        if (! $this->role) {
            return 0;
        }

        return UserRole::tryFrom($this->role)?->level() ?? 0;
    }

    // ─── Locale preference ────────────────────────────────────────────────────

    /**
     * Preferred locale for notifications.
     * Resolution order: user->language → app.locale → hard fallback 'vi'.
     * Setting model dependency removed — config('app.locale') is the tenant default.
     */
    public function preferredLocale(): string
    {
        if (! empty($this->language) && in_array($this->language, ['vi', 'en'], true)) {
            return $this->language;
        }

        $appLocale = config('app.locale', 'vi');
        if (in_array($appLocale, ['vi', 'en'], true)) {
            return $appLocale;
        }

        return 'vi';
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $email
 * @property string $first_name
 * @property string $last_name
 * @property string $role
 * @property string $token
 * @property string|null $token_lookup_hash
 * @property Carbon $expires_at
 * @property int $sent_by
 * @property int|null $accepted_by_user_id
 * @property Carbon|null $accepted_at
 * @property string $status
 * @property string|null $national_id_encrypted
 * @property string|null $national_id_hash
 * @property Carbon|null $dob
 * @property string|null $gender
 * @property string|null $country_code
 * @property string|null $address_province
 * @property string|null $address_commune
 * @property string|null $address_street
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $national_id
 * @property-read User    $sender
 * @property-read User|null $acceptedBy
 */
class UserInvitation extends Model
{
    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'role',
        'token',
        'token_lookup_hash',
        'expires_at',
        'sent_by',
        'accepted_by_user_id',
        'accepted_at',
        'status',
        'national_id_encrypted',
        'national_id_hash',
        'dob',
        'gender',
        'country_code',
        'address_province',
        'address_commune',
        'address_street',
    ];

    protected $hidden = [
        'national_id_encrypted',
        'national_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'dob' => 'date',
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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    /**
     * @param  Builder<UserInvitation>  $query
     * @return Builder<UserInvitation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<UserInvitation>  $query
     * @return Builder<UserInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

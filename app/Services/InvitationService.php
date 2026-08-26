<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\SendInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Manages the full invitation lifecycle:
 *   send  → generate raw token → hash + store → dispatch notification email
 *   accept → validate hashed token → create User atomically → flag invitation
 *   resend → regenerate token + extend expiry → re-dispatch notification
 *   revoke → flag status=revoked (token immediately invalid)
 *   expirePending → cron: flag pending rows past expires_at → expired
 */
class InvitationService
{
    private const EXPIRY_HOURS = 48;

    /**
     * @param array{
     *   national_id?: ?string, dob?: ?string, gender?: ?string,
     *   country_code?: ?string, address_province?: ?string,
     *   address_commune?: ?string, address_street?: ?string,
     * } $identity
     *
     * @throws HttpException 422
     */
    public function send(
        string $email,
        string $firstName,
        string $lastName,
        string $role,
        User $admin,
        array $identity = [],
    ): UserInvitation {
        $this->assertNoConflict($email);

        ['raw' => $raw, 'hash' => $hash, 'lookup' => $lookup] = $this->generateToken();
        $expiresAt = now()->addHours(self::EXPIRY_HOURS);

        $invitation = new UserInvitation([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'token' => $hash,
            'token_lookup_hash' => $lookup,
            'expires_at' => $expiresAt,
            'sent_by' => $admin->id,
            'status' => 'pending',
            'dob' => $identity['dob'] ?? null,
            'gender' => $identity['gender'] ?? null,
            'country_code' => $identity['country_code'] ?? null,
            'address_province' => $identity['address_province'] ?? null,
            'address_commune' => $identity['address_commune'] ?? null,
            'address_street' => $identity['address_street'] ?? null,
        ]);

        if (! empty($identity['national_id'])) {
            $invitation->national_id = $identity['national_id'];
        }

        $invitation->save();

        $this->dispatchEmail($invitation, $raw);

        // AuditLog removed.

        return $invitation;
    }

    /**
     * Accept an invitation — creates the User and marks invitation accepted.
     *
     * @throws HttpException 404 / 422
     */
    public function accept(string $rawToken, string $password): User
    {
        $invitation = $this->findByRawToken($rawToken);
        $this->assertAcceptable($invitation);

        return DB::transaction(function () use ($invitation, $password) {
            $locked = UserInvitation::lockForUpdate()->find($invitation->id);
            if (! $locked || $locked->status !== 'pending') {
                throw new HttpException(422, __('api.invitation.already_used'));
            }

            $user = User::create([
                'first_name' => $locked->first_name,
                'last_name' => $locked->last_name,
                'email' => $locked->email,
                'password' => $password,
                'status' => UserStatus::Active,
                'dob' => $locked->dob,
                'gender' => $locked->gender,
                'country_code' => $locked->country_code,
                'address_province' => $locked->address_province,
                'address_commune' => $locked->address_commune,
                'address_street' => $locked->address_street,
            ]);
            $user->forceFill([
                'role' => $locked->role,
                'email_verified_at' => now(),
            ])->save();

            if ($locked->national_id !== null) {
                $user->national_id = $locked->national_id;
                $user->save();
            }

            $locked->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            // AuditLog removed.

            return $user;
        });
    }

    /**
     * @throws HttpException 422
     */
    public function resend(UserInvitation $invitation, User $admin): UserInvitation
    {
        if ($invitation->status !== 'pending') {
            throw new HttpException(422, __('api.invitation.not_pending'));
        }

        ['raw' => $raw, 'hash' => $hash, 'lookup' => $lookup] = $this->generateToken();

        $invitation->update([
            'token' => $hash,
            'token_lookup_hash' => $lookup,
            'expires_at' => now()->addHours(self::EXPIRY_HOURS),
        ]);

        $refreshed = $invitation->fresh() ?? $invitation;
        $this->dispatchEmail($refreshed, $raw);

        // AuditLog removed.

        return $refreshed;
    }

    /**
     * @throws HttpException 422
     */
    public function revoke(UserInvitation $invitation, User $admin): void
    {
        if ($invitation->status !== 'pending') {
            throw new HttpException(422, __('api.invitation.not_pending'));
        }

        $invitation->update(['status' => 'revoked']);

        // AuditLog removed.
    }

    public function expirePending(): int
    {
        return UserInvitation::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    public function findActiveByRawToken(string $rawToken): ?UserInvitation
    {
        $invitation = UserInvitation::where('token_lookup_hash', $this->lookupHash($rawToken))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation || ! Hash::check($rawToken, $invitation->token)) {
            return null;
        }

        return $invitation;
    }

    /**
     * Latest pending invitation for an email, or null.
     */
    public function findPendingByEmail(?string $email): ?UserInvitation
    {
        if ($email === null || $email === '') {
            return null;
        }

        return UserInvitation::where('email', $email)
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /** @return array{raw: string, hash: string, lookup: string} */
    private function generateToken(): array
    {
        $raw = Str::random(64);

        return [
            'raw' => $raw,
            'hash' => Hash::make($raw),
            'lookup' => $this->lookupHash($raw),
        ];
    }

    private function lookupHash(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, config('app.key'));
    }

    /** @throws HttpException 404 */
    private function findByRawToken(string $rawToken): UserInvitation
    {
        $lookup = $this->lookupHash($rawToken);

        $invitation = $this->findActiveByRawToken($rawToken);
        if ($invitation) {
            return $invitation;
        }

        $other = UserInvitation::where('token_lookup_hash', $lookup)
            ->where('status', '!=', 'pending')
            ->first();

        if ($other && Hash::check($rawToken, $other->token)) {
            return $other;
        }

        throw new HttpException(404, __('api.invitation.not_found'));
    }

    /** @throws HttpException 422 */
    private function assertAcceptable(UserInvitation $invitation): void
    {
        if ($invitation->status === 'accepted') {
            throw new HttpException(422, __('api.invitation.already_used'));
        }
        if ($invitation->status === 'revoked') {
            throw new HttpException(422, __('api.invitation.revoked_error'));
        }
        if ($invitation->status === 'expired' || $invitation->isExpired()) {
            throw new HttpException(422, __('api.invitation.expired'));
        }
    }

    /** @throws HttpException 422 */
    private function assertNoConflict(string $email): void
    {
        if (User::where('email', $email)->exists()) {
            throw new HttpException(422, __('api.invitation.email_conflict'));
        }
        if (UserInvitation::where('email', $email)->where('status', 'pending')->exists()) {
            throw new HttpException(422, __('api.invitation.email_conflict'));
        }
    }

    private function dispatchEmail(UserInvitation $invitation, string $rawToken): void
    {
        $frontendOrigin = rtrim(config('app.frontend_url', config('app.url')), '/');
        $acceptUrl = $frontendOrigin.'/admin/accept-invite?token='.urlencode($rawToken);
        $tenantName = config('app.name', 'Admin');

        $notification = new SendInvitationNotification(
            rawToken: $rawToken,
            firstName: $invitation->first_name,
            acceptUrl: $acceptUrl,
            tenantName: $tenantName,
        );

        Notification::route('mail', $invitation->email)->notify($notification);
    }
}

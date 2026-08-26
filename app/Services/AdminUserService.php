<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Admin lifecycle operations on User accounts (lock/unlock/deactivate/activate/etc).
 *
 * 3-gate authorization per mutation:
 *   Gate 1 — caller has the action permission (enforced by route middleware 'role:admin')
 *   Gate 2 — caller is not the target (no self-lockout)
 *   Gate 3 — caller's role level is strictly greater than target's
 *
 * Permission module decoupled: isSuperAdmin() → User::isSuperAdmin() (role === 'admin').
 * getRoleLevel() → UserRole enum level() — no DB Role table needed.
 * Bulk filterAllowedTargets: inline level comparison without roles JOIN.
 */
class AdminUserService
{
    public function __construct(
        protected PasswordResetService $passwordReset,
        protected VerificationService $verification,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    // 3-gate authorization
    // ────────────────────────────────────────────────────────────────────────

    private function ensureCanModify(User $target, User $admin, string $action): void
    {
        // Gate 2: self-action block (Gate 1 handled by route middleware role:admin)
        if ($target->id === $admin->id) {
            throw new HttpException(403, __('users.errors.cannot_self_modify'));
        }

        // Gate 3: privilege hierarchy — admin bypasses (isSuperAdmin = role === 'admin')
        if (! $admin->isSuperAdmin() && $target->getRoleLevel() >= $admin->getRoleLevel()) {
            throw new HttpException(403, __('users.errors.target_higher_role'));
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Per-user lifecycle actions
    // ────────────────────────────────────────────────────────────────────────

    public function lock(User $target, User $admin, string $reason): User
    {
        $this->ensureCanModify($target, $admin, 'user.lock');

        DB::transaction(function () use ($target, $admin, $reason) {
            $target->update([
                'status' => UserStatus::Locked,
                'locked_at' => now(),
                'locked_by' => $admin->id,
                'lock_reason' => $reason,
            ]);
            Session::where('user_id', $target->id)->delete();
            // AuditLog removed.
        });

        return $target->fresh() ?? $target;
    }

    public function unlock(User $target, User $admin): User
    {
        $this->ensureCanModify($target, $admin, 'user.unlock');

        $target->update([
            'status' => UserStatus::Active,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
        ]);
        // AuditLog removed.

        return $target->fresh() ?? $target;
    }

    public function deactivate(User $target, User $admin, string $reason): User
    {
        $this->ensureCanModify($target, $admin, 'user.deactivate');

        DB::transaction(function () use ($target, $admin, $reason) {
            $target->update([
                'status' => UserStatus::Inactive,
                'deactivated_at' => now(),
                'deactivated_by' => $admin->id,
                'deactivation_reason' => $reason,
            ]);
            Session::where('user_id', $target->id)->delete();
            // AuditLog removed.
        });

        return $target->fresh() ?? $target;
    }

    public function activate(User $target, User $admin): User
    {
        $this->ensureCanModify($target, $admin, 'user.activate');

        $target->update([
            'status' => UserStatus::Active,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
        ]);
        // AuditLog removed.

        return $target->fresh() ?? $target;
    }

    public function sendPasswordReset(User $target, User $admin): void
    {
        $this->ensureCanModify($target, $admin, 'user.reset_password');

        $identifier = $target->email ?? $target->phone;
        if (! $identifier) {
            throw new HttpException(422, __('users.errors.no_reset_channel'));
        }

        $this->passwordReset->sendResetRequest($identifier);
        // AuditLog removed.
    }

    public function revokeSessions(User $target, User $admin): int
    {
        $this->ensureCanModify($target, $admin, 'user.revoke_sessions');

        $count = Session::where('user_id', $target->id)->delete();
        // AuditLog removed.

        return $count;
    }

    public function changeRole(User $target, User $admin, string $newRoleSlug): User
    {
        $this->ensureCanModify($target, $admin, 'user.change_role');

        // Caller cannot promote target to a role >= their own level (admin bypass).
        $newLevel = UserRole::tryFrom($newRoleSlug)?->level() ?? 0;
        if (! $admin->isSuperAdmin() && $newLevel >= $admin->getRoleLevel()) {
            throw new HttpException(403, __('users.errors.target_higher_role'));
        }

        $target->forceFill(['role' => $newRoleSlug])->save();
        // AuditLog removed.

        return $target->fresh() ?? $target;
    }

    public function softDeleteUser(User $target, User $admin): void
    {
        $this->ensureCanModify($target, $admin, 'user.delete');

        DB::transaction(static function () use ($target) {
            Session::where('user_id', $target->id)->delete();
            $target->delete();
            // AuditLog removed.
        });
    }

    public function restoreUser(User $target, User $admin): User
    {
        if (! $target->trashed()) {
            throw new HttpException(422, __('users.errors.not_trashed'));
        }

        $this->ensureCanModify($target, $admin, 'user.restore');

        $target->restore();
        $target->update([
            'status' => UserStatus::Inactive,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
        ]);
        // AuditLog removed.

        return $target->fresh() ?? $target;
    }

    public function permanentDelete(User $target, User $admin): void
    {
        $this->ensureCanModify($target, $admin, 'user.permanent_delete');

        if (! $target->trashed()) {
            throw new HttpException(422, __('users.errors.must_soft_delete_first'));
        }

        $target->forceDelete();
        // AuditLog removed.
    }

    public function resendVerification(User $target, User $admin, string $channel): void
    {
        $this->ensureCanModify($target, $admin, 'user.resend_verification');

        if (! in_array($channel, ['email', 'phone'], true)) {
            throw new HttpException(422, __('users.errors.invalid_verification_channel'));
        }

        $identifier = $channel === 'email' ? $target->email : $target->phone;
        if (! $identifier) {
            throw new HttpException(422, __('users.errors.no_verification_identifier'));
        }

        $this->verification->sendVerification($target, $identifier, $channel);
        // AuditLog removed.
    }

    // ────────────────────────────────────────────────────────────────────────
    // Bulk actions
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Filter target IDs to those the caller can modify (3-gate per row).
     * Replaces roles JOIN with UserRole enum — no Permission DB table needed.
     *
     * @param  int[]  $targetIds
     * @return int[]
     */
    private function filterAllowedTargets(array $targetIds, User $admin, string $action): array
    {
        $callerLevel = $admin->getRoleLevel();
        $isSuper = $admin->isSuperAdmin();

        $rows = User::query()
            ->whereIn('id', $targetIds)
            ->where('id', '!=', $admin->id)
            ->where('status', '!=', UserStatus::PendingInvite->value)
            ->select(['id', 'role'])
            ->get();

        return $rows
            ->filter(fn ($r) => $isSuper || (UserRole::tryFrom($r->role)?->level() ?? 0) < $callerLevel)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  int[]  $targetIds
     * @return array{success:int,failed:int}
     */
    public function bulkChangeRole(array $targetIds, User $admin, string $newRoleSlug): array
    {
        $newLevel = UserRole::tryFrom($newRoleSlug)?->level() ?? 0;
        if (! $admin->isSuperAdmin() && $newLevel >= $admin->getRoleLevel()) {
            throw new HttpException(403, __('users.errors.target_higher_role'));
        }

        $allowed = $this->filterAllowedTargets($targetIds, $admin, 'user.change_role');
        if (empty($allowed)) {
            return ['success' => 0, 'failed' => count($targetIds)];
        }

        DB::transaction(static function () use ($allowed, $newRoleSlug) {
            User::whereIn('id', $allowed)->update(['role' => $newRoleSlug]);
            // AuditLog removed (bulk).
        });

        return ['success' => count($allowed), 'failed' => count($targetIds) - count($allowed)];
    }

    /**
     * @param  int[]  $targetIds
     * @return array{success:int,failed:int,skipped:int}
     */
    public function bulkSendPasswordReset(array $targetIds, User $admin): array
    {
        $allowed = $this->filterAllowedTargets($targetIds, $admin, 'user.reset_password');

        $sent = 0;
        $skipped = 0;

        foreach (User::whereIn('id', $allowed)->get() as $target) {
            $identifier = $target->email ?? $target->phone;
            if (! $identifier) {
                $skipped++;

                continue;
            }
            try {
                $this->passwordReset->sendResetRequest($identifier);
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $skipped++;
            }
        }

        return [
            'success' => $sent,
            'failed' => count($targetIds) - count($allowed),
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  int[]  $targetIds
     * @return array{success:int,failed:int}
     */
    public function bulkDeactivate(array $targetIds, User $admin, string $reason): array
    {
        $allowed = $this->filterAllowedTargets($targetIds, $admin, 'user.deactivate');
        if (empty($allowed)) {
            return ['success' => 0, 'failed' => count($targetIds)];
        }

        DB::transaction(function () use ($allowed, $admin, $reason) {
            User::whereIn('id', $allowed)->update([
                'status' => UserStatus::Inactive->value,
                'deactivated_at' => now(),
                'deactivated_by' => $admin->id,
                'deactivation_reason' => $reason,
            ]);
            Session::whereIn('user_id', $allowed)->delete();
            // AuditLog removed (bulk).
        });

        return ['success' => count($allowed), 'failed' => count($targetIds) - count($allowed)];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Statistics
    // ────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function statistics(): array
    {
        $base = User::query();

        $statusCounts = (clone $base)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return [
            'total' => (clone $base)->count(),
            'active' => (int) ($statusCounts[UserStatus::Active->value] ?? 0),
            'inactive' => (int) ($statusCounts[UserStatus::Inactive->value] ?? 0),
            'locked' => (int) ($statusCounts[UserStatus::Locked->value] ?? 0),
            'without_2fa' => (clone $base)->whereNull('two_factor_confirmed_at')->count(),
            'never_logged_in' => (clone $base)->whereNull('last_login_at')->count(),
            'inactive_30d' => (clone $base)->where(function ($q) {
                $q->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', now()->subDays(30));
            })->count(),
            'trashed' => User::onlyTrashed()->count(),
        ];
    }

    /**
     * Active sessions for a user, formatted for the API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsFor(User $user): array
    {
        return Session::where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (Session $s): array => [
                'id' => $s->id,
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_activity' => $s->last_activity ? date('c', (int) $s->last_activity) : null,
            ])
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\BulkDeleteUsersRequest;
use App\Http\Requests\User\BulkUserActionRequest;
use App\Http\Requests\User\ChangeUserRoleRequest;
use App\Http\Requests\User\DeactivateUserRequest;
use App\Http\Requests\User\InviteUserRequest;
use App\Http\Requests\User\LockUserRequest;
use App\Http\Requests\User\ResendVerificationRequest;
use App\Http\Requests\User\SearchUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminUserService;
use App\Services\InvitationService;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserController extends Controller
{
    use ApiResponse;

    private const ALLOWED_SORT_COLUMNS = ['id', 'first_name', 'last_name', 'email', 'created_at', 'updated_at'];

    public function __construct(
        protected UserService $userService,
        protected AdminUserService $adminUserService,
        protected InvitationService $invitationService,
    ) {}

    // ── List / CRUD ──────────────────────────────────────────────────────────

    public function index(SearchUserRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortColumn = $request->input('sort', 'created_at');

        $sort = [
            'column' => in_array($sortColumn, self::ALLOWED_SORT_COLUMNS, true) ? $sortColumn : 'created_at',
            'direction' => in_array($request->input('direction'), ['asc', 'desc'], true)
                ? $request->input('direction')
                : 'desc',
        ];

        $users = $this->userService->getPaginatedUsers($filters, (int) $request->input('per_page', 10), $sort);

        return $this->collection(UserResource::collection($users));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->resource(new UserResource($user), __('api.user.create_success'), 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['lockedBy:id,first_name,last_name', 'deactivatedBy:id,first_name,last_name']);

        return $this->resource(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return $this->resource(new UserResource($user), __('api.user.update_success'));
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return $this->error(__('api.user.cannot_delete_self'), 403);
        }

        $this->userService->delete($user);

        return $this->success(null, __('api.user.delete_success'));
    }

    public function bulkDestroy(BulkDeleteUsersRequest $request): JsonResponse
    {
        $deleted = $this->userService->bulkDelete($request->ids);

        return $this->success(
            ['deleted_count' => $deleted],
            __('api.user.bulk_delete_success', ['count' => $deleted])
        );
    }

    /** GET /users/me — authenticated user's own profile. */
    public function me(Request $request): JsonResponse
    {
        return $this->resource(new UserResource($this->currentUser($request)));
    }

    public function statistics(): JsonResponse
    {
        return $this->success($this->adminUserService->statistics());
    }

    // ── Admin lifecycle actions (3-gate via AdminUserService) ────────────────

    public function lock(LockUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->lock($user, $this->currentUser($request), (string) $request->input('reason'));

        return $this->resource(new UserResource($updated), __('users.success.locked'));
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->unlock($user, $this->currentUser($request));

        return $this->resource(new UserResource($updated), __('users.success.unlocked'));
    }

    public function deactivate(DeactivateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->deactivate($user, $this->currentUser($request), (string) $request->input('reason'));

        return $this->resource(new UserResource($updated), __('users.success.deactivated'));
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->activate($user, $this->currentUser($request));

        return $this->resource(new UserResource($updated), __('users.success.activated'));
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->adminUserService->sendPasswordReset($user, $this->currentUser($request));

        return $this->success(null, __('users.success.password_reset_sent'));
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $count = $this->adminUserService->revokeSessions($user, $this->currentUser($request));

        return $this->success(['revoked' => $count], __('users.success.sessions_revoked', ['count' => $count]));
    }

    public function changeRole(ChangeUserRoleRequest $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->changeRole($user, $this->currentUser($request), (string) $request->input('role'));

        return $this->resource(new UserResource($updated), __('users.success.role_changed'));
    }

    public function resendVerification(ResendVerificationRequest $request, User $user): JsonResponse
    {
        $this->adminUserService->resendVerification($user, $this->currentUser($request), (string) $request->input('channel'));

        return $this->success(null, __('users.success.verification_resent'));
    }

    public function permanentDelete(Request $request, User $user): JsonResponse
    {
        $this->adminUserService->permanentDelete($user, $this->currentUser($request));

        return $this->success(null, __('users.success.permanently_deleted'));
    }

    public function restore(Request $request, User $user): JsonResponse
    {
        $updated = $this->adminUserService->restoreUser($user, $this->currentUser($request));

        return $this->resource(new UserResource($updated), __('users.success.restored'));
    }

    /** GET /users/{user}/sessions — active sessions for a given user. */
    public function sessions(Request $request, User $user): JsonResponse
    {
        return $this->success(['sessions' => $this->adminUserService->sessionsFor($user)]);
    }

    // ── Bulk admin actions ───────────────────────────────────────────────────

    public function bulkChangeRole(BulkUserActionRequest $request): JsonResponse
    {
        // Validate role exists in UserRole enum (no roles DB table join needed).
        $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_column(UserRole::cases(), 'value'))],
        ]);

        $result = $this->adminUserService->bulkChangeRole(
            $request->input('user_ids'),
            $this->currentUser($request),
            (string) $request->input('role'),
        );

        return $this->success($result, __('users.success.bulk_done'));
    }

    public function bulkSendPasswordReset(BulkUserActionRequest $request): JsonResponse
    {
        $result = $this->adminUserService->bulkSendPasswordReset(
            $request->input('user_ids'),
            $this->currentUser($request),
        );

        return $this->success($result, __('users.success.bulk_done'));
    }

    public function bulkDeactivate(BulkUserActionRequest $request): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:255']);

        $result = $this->adminUserService->bulkDeactivate(
            $request->input('user_ids'),
            $this->currentUser($request),
            (string) $request->input('reason'),
        );

        return $this->success($result, __('users.success.bulk_done'));
    }

    // ── Invitation actions ───────────────────────────────────────────────────

    /** POST /users/invite — send invitation email to a new admin user. */
    public function invite(InviteUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $invitation = $this->invitationService->send(
            email: $data['email'],
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            role: $data['role'],
            admin: $this->currentUser($request),
        );

        return $this->success(
            ['invitation_id' => $invitation->id, 'expires_at' => $invitation->expires_at->toIso8601String()],
            __('api.invitation.sent'),
            201,
        );
    }

    /** POST /users/{user}/resend-invitation — regenerate token + resend. */
    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $invitation = $this->invitationService->findPendingByEmail($user->email);

        if (! $invitation) {
            throw new HttpException(404, __('api.invitation.not_found'));
        }

        $updated = $this->invitationService->resend($invitation, $this->currentUser($request));

        return $this->success(['expires_at' => $updated->expires_at->toIso8601String()], __('api.invitation.resent'));
    }

    /** POST /users/{user}/revoke-invitation — revoke pending invitation. */
    public function revokeInvitation(Request $request, User $user): JsonResponse
    {
        $invitation = $this->invitationService->findPendingByEmail($user->email);

        if (! $invitation) {
            throw new HttpException(404, __('api.invitation.not_found'));
        }

        $this->invitationService->revoke($invitation, $this->currentUser($request));

        return $this->success(null, __('api.invitation.revoked'));
    }
}

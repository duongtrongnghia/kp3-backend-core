<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AcceptInvitationRequest;
use App\Services\InvitationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public controller for the invitation accept flow.
 * No auth middleware — rate-limited at route level.
 */
class AcceptInvitationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected InvitationService $invitationService,
    ) {}

    /**
     * GET /invitations/{token}
     *
     * Returns valid:false instead of 4xx so the frontend can render a friendly
     * expired/revoked page without needing to parse status codes.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitationService->findActiveByRawToken($token);

        if (! $invitation) {
            return $this->success([
                'valid' => false,
                'email' => null,
                'first_name' => null,
                'last_name' => null,
                'expires_at' => null,
            ]);
        }

        return $this->success([
            'valid' => true,
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /invitations/{token}/accept
     *
     * Creates the User, flags invitation accepted, returns redirect hint for FE.
     */
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->invitationService->accept($token, (string) $request->input('password'));

        return $this->success(
            ['redirect_to' => '/login', 'email' => $user->email],
            __('api.invitation.accepted'),
            201,
        );
    }
}

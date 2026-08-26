<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthService $authService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->authService->getActiveSessions($this->currentUser($request));

        return $this->collection(
            SessionResource::collection($result['sessions']),
            additional: [
                'total' => $result['total'],
                'has_more' => $result['has_more'],
            ]
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! preg_match('/^[a-zA-Z0-9]{1,255}$/', $id)) {
            return $this->error(__('validation.invalid'), 400);
        }

        if (! $this->authService->logoutDevice($this->currentUser($request), $id)) {
            return $this->error(__('api.auth.logout_device_current'), 403);
        }

        return $this->success(null, __('api.auth.logout_device_success'));
    }

    public function logoutOtherDevices(Request $request): JsonResponse
    {
        $this->authService->logoutOtherDevices($this->currentUser($request));

        return $this->success(null, __('api.auth.logout_other_devices_success'));
    }
}

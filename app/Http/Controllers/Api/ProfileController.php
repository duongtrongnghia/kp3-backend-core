<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\Profile\ProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAppearanceRequest;
use App\Http\Requests\UpdateLanguageRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Actions folded in (no app/Actions/ dir):
 *   UpdateLanguageAction → ProfileService::updateLanguage()
 *   UpdatePasswordAction → ProfileService::updatePassword()
 */
class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ProfileService $profileService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->resource(new UserResource($this->currentUser($request)));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profileService->update(
            $this->currentUser($request),
            ProfileData::fromArray($request->validated()),
            $request->file('avatar')
        );

        return $this->resource(new UserResource($user), __('api.profile.update_success'));
    }

    public function updateLanguage(UpdateLanguageRequest $request): JsonResponse
    {
        $user = $this->profileService->updateLanguage($this->currentUser($request), $request->language);

        return $this->resource(new UserResource($user), __('api.profile.update_success'));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $this->profileService->updatePassword($this->currentUser($request), $request->password);

        return $this->success(null, __('api.profile.password_update_success'));
    }

    public function updateAppearance(UpdateAppearanceRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $user->update(['appearance_settings' => $request->validated()]);

        return $this->resource(new UserResource($user->fresh() ?? $user), __('api.profile.appearance_update_success'));
    }
}

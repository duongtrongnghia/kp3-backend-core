<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\SocialAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

class SocialAuthController extends Controller
{
    use ApiResponse;

    private const ALLOWED_PROVIDERS = ['google', 'facebook'];

    public function __construct(
        protected SocialAuthService $socialAuthService,
    ) {}

    private function validateProvider(string $provider): ?JsonResponse
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            return $this->error(__('api.auth.social_redirect_failed'), 400);
        }

        return null;
    }

    /**
     * Cast Socialite driver to AbstractProvider (concrete Two OAuth base) to access stateless().
     * The Contracts\Provider interface does not declare stateless(); the concrete class does.
     */
    private function driver(string $provider): AbstractProvider
    {
        /** @var AbstractProvider */
        return Socialite::driver($provider);
    }

    /** Return the OAuth provider redirect URL for the frontend to follow. */
    public function redirectToProvider(string $provider): JsonResponse
    {
        if ($error = $this->validateProvider($provider)) {
            return $error;
        }

        $url = $this->driver($provider)->stateless()->redirect()->getTargetUrl();

        return $this->success(['url' => $url]);
    }

    /** Handle OAuth callback — create/find user, start session. */
    public function handleProviderCallback(Request $request, string $provider): JsonResponse
    {
        if ($error = $this->validateProvider($provider)) {
            return $error;
        }

        $socialiteUser = $this->driver($provider)->stateless()->user();
        $user = $this->socialAuthService->findOrCreateUser($socialiteUser, $provider);

        Auth::login($user);
        $request->session()->regenerate();

        return $this->resource(new UserResource($user), __('api.auth.login_success'));
    }

    /** Return OAuth redirect URL for the account-linking flow. */
    public function redirectToLink(string $provider): JsonResponse
    {
        if ($error = $this->validateProvider($provider)) {
            return $error;
        }

        $redirectUri = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
            ."/auth/{$provider}/link/callback";

        $url = $this->driver($provider)
            ->stateless()
            ->redirectUrl($redirectUri)
            ->redirect()
            ->getTargetUrl();

        return $this->success(['url' => $url]);
    }

    /** Handle callback for account linking (authenticated user). */
    public function handleLinkCallback(Request $request, string $provider): JsonResponse
    {
        if ($error = $this->validateProvider($provider)) {
            return $error;
        }

        $redirectUri = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
            ."/auth/{$provider}/link/callback";

        $socialiteUser = $this->driver($provider)
            ->stateless()
            ->redirectUrl($redirectUri)
            ->user();

        $user = $this->currentUser($request);
        $this->socialAuthService->linkAccount($user, $socialiteUser, $provider);

        return $this->resource(
            new UserResource($user->fresh() ?? $user),
            __('api.auth.social_linked', ['provider' => ucfirst($provider)])
        );
    }

    /** Unlink a social account from the authenticated user. */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        if ($error = $this->validateProvider($provider)) {
            return $error;
        }

        $this->socialAuthService->unlinkAccount($this->currentUser($request), $provider);

        return $this->success(null, __('api.auth.social_unlinked', ['provider' => ucfirst($provider)]));
    }
}

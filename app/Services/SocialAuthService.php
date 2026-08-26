<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use App\Traits\Transactional;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAuthService
{
    use Transactional;

    /** @return array{email: ?string, phone: ?string, primary: ?string} */
    protected function extractContactsFromProvider(SocialiteUser $socialiteUser, string $provider): array
    {
        $email = $socialiteUser->getEmail();
        $phone = null;

        if ($provider === 'facebook') {
            $phone = $socialiteUser->user['phone_number'] ?? null;
        }

        return [
            'email' => $email,
            'phone' => $phone,
            'primary' => $email ?? $phone,
        ];
    }

    protected function findUserByContacts(?string $email, ?string $phone): ?User
    {
        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                return $user;
            }
        }

        if ($phone) {
            return User::where('phone', $phone)->first();
        }

        return null;
    }

    /**
     * Find or create user from Socialite data.
     *
     * Flow 1: Social account already exists → login immediately.
     * Flow 2: Brand new user → create user + social account.
     * Flow 3: Email/phone belongs to an existing account → reject (must link manually).
     *
     * @throws ValidationException
     */
    public function findOrCreateUser(SocialiteUser $socialiteUser, string $provider): User
    {
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($socialAccount) {
            $linked = $socialAccount->user()->firstOrFail();

            /** @var User $linked */
            return $linked;
        }

        $contacts = $this->extractContactsFromProvider($socialiteUser, $provider);
        $email = $contacts['email'];
        $phone = $contacts['phone'];
        $primary = $contacts['primary'];

        if (! $primary) {
            throw ValidationException::withMessages([
                'provider' => __('api.auth.email_required_for_social_login', ['provider' => ucfirst($provider)]),
            ]);
        }

        if ($this->findUserByContacts($email, $phone)) {
            throw ValidationException::withMessages([
                'provider' => __('api.auth.social_email_exists'),
            ]);
        }

        $randomPassword = Hash::make(Str::random(40));

        return $this->transactional(function () use ($socialiteUser, $provider, $email, $phone, $randomPassword) {
            $fullName = $socialiteUser->getName() ?? $socialiteUser->getNickname();
            $names = explode(' ', (string) $fullName, 2);

            $user = new User;
            $user->fill([
                'first_name' => $names[0],
                'last_name' => $names[1] ?? '',
                'email' => $email,
                'phone' => $phone,
                'avatar' => $socialiteUser->getAvatar(),
                'password' => $randomPassword,
            ]);
            $user->email_verified_at = $email ? now() : null;
            $user->phone_verified_at = $phone ? now() : null;
            $user->role = UserRole::CUSTOMER->value;
            $user->save();

            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $socialiteUser->getId(),
                'avatar' => $socialiteUser->getAvatar(),
            ]);

            return $user;
        });
    }

    /**
     * @throws ValidationException
     */
    public function linkAccount(User $user, SocialiteUser $socialiteUser, string $provider): void
    {
        $existingAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($existingAccount && $existingAccount->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'provider' => __('api.auth.social_already_linked'),
            ]);
        }

        $user->socialAccounts()->updateOrCreate(
            ['provider' => $provider],
            [
                'provider_id' => $socialiteUser->getId(),
                'avatar' => $socialiteUser->getAvatar(),
            ],
        );

        // AuditLog removed.
    }

    public function unlinkAccount(User $user, string $provider): void
    {
        $user->socialAccounts()->where('provider', $provider)->delete();

        // AuditLog removed.
    }
}

<?php

declare(strict_types=1);

use App\Enums\TwoFactorType;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;

use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * Create a user with confirmed app-based 2FA and a known TOTP secret.
 */
function userWithAppTwoFactor(string $secret): User
{
    return User::factory()->create([
        'email' => '2fa@example.com',
        'password' => Hash::make('secret123'),
        'email_verified_at' => now(),
        'two_factor_type' => TwoFactorType::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);
}

it('returns a 2FA challenge instead of logging in directly', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    userWithAppTwoFactor($secret);

    postJson('/api/v1/auth/login', [
        'identifier' => '2fa@example.com',
        'password' => 'secret123',
    ])
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['flow_token']]);
});

it('verifies a valid TOTP code', function (): void {
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $user = userWithAppTwoFactor($secret);

    $validCode = $google->getCurrentOtp($secret);

    expect(app(TwoFactorService::class)->verify($user, $validCode))->toBeTrue();
});

it('rejects an invalid TOTP code', function (): void {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userWithAppTwoFactor($secret);

    expect(app(TwoFactorService::class)->verify($user, '000000'))->toBeFalse();
});

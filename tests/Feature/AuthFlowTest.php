<?php

declare(strict_types=1);

use App\Enums\OtpType;
use App\Models\Otp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('registers a new user, stores a hashed OTP, and returns a flow token', function (): void {
    Notification::fake();

    postJson('/api/v1/auth/register', [
        'email' => 'newbie@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])
        ->assertCreated()
        ->assertJsonPath('data.identifier', 'newbie@example.com')
        ->assertJsonStructure(['data' => ['flow_token', 'masked_identifier']]);

    $user = User::where('email', 'newbie@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();

    // OTP stored hashed (never plaintext — the column holds a bcrypt hash).
    $otp = Otp::where('identifier', 'newbie@example.com')->first();
    expect($otp)->not->toBeNull();
    expect(str_starts_with($otp->code, '$2y$'))->toBeTrue(); // bcrypt hash, not the 6-digit code
});

it('verifies a registration OTP and marks the email verified', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);

    // generate() returns the plaintext code (only place it is exposed).
    $code = app(OtpService::class)->generate('verify@example.com', OtpType::REGISTRATION, $user);

    postJson('/api/v1/auth/verify-otp', [
        'identifier' => 'verify@example.com',
        'code' => $code,
        'type' => OtpType::REGISTRATION->value,
    ])->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects an invalid OTP', function (): void {
    Notification::fake();
    User::factory()->unverified()->create(['email' => 'bad@example.com']);
    app(OtpService::class)->generate('bad@example.com', OtpType::REGISTRATION);

    postJson('/api/v1/auth/verify-otp', [
        'identifier' => 'bad@example.com',
        'code' => '000000',
        'type' => OtpType::REGISTRATION->value,
    ])->assertStatus(422);
});

it('logs in a verified user with correct credentials', function (): void {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('secret123'),
        'email_verified_at' => now(),
    ]);

    postJson('/api/v1/auth/login', [
        'identifier' => 'login@example.com',
        'password' => 'secret123',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'login@example.com');
});

it('rejects login with a wrong password', function (): void {
    User::factory()->create([
        'email' => 'login2@example.com',
        'password' => Hash::make('secret123'),
        'email_verified_at' => now(),
    ]);

    postJson('/api/v1/auth/login', [
        'identifier' => 'login2@example.com',
        'password' => 'WRONG',
    ])->assertStatus(422);
});

it('returns the authenticated user and 401 when unauthenticated', function (): void {
    getJson('/api/v1/user')->assertUnauthorized();

    $user = User::factory()->create(['email_verified_at' => now()]);

    Sanctum::actingAs($user);
    getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('logs out an authenticated user', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    Sanctum::actingAs($user);

    postJson('/api/v1/auth/logout')->assertOk();
});

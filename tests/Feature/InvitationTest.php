<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('lets an admin create a pending invitation', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::ADMIN->value]);

    $invitation = app(InvitationService::class)->send(
        email: 'invitee@example.com',
        firstName: 'In',
        lastName: 'Vitee',
        role: UserRole::CUSTOMER->value,
        admin: $admin,
    );

    expect($invitation->status)->toBe('pending');
    expect(UserInvitation::where('email', 'invitee@example.com')->where('status', 'pending')->exists())->toBeTrue();
    expect($invitation->sent_by)->toBe($admin->id);
});

it('rejects a duplicate invitation for the same email', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::ADMIN->value]);
    $service = app(InvitationService::class);

    $service->send('dup@example.com', 'A', 'B', UserRole::CUSTOMER->value, $admin);

    expect(fn () => $service->send('dup@example.com', 'A', 'B', UserRole::CUSTOMER->value, $admin))
        ->toThrow(HttpException::class);
});

it('rejects accepting an invalid invitation token', function (): void {
    postJson('/api/v1/invitations/not-a-real-token/accept', [
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ])->assertNotFound();
});

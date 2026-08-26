<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AcceptInvitationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // ── Public auth routes ────────────────────────────────────────────────────

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/verify-2fa', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/auth/verify-link', [AuthController::class, 'verifyByToken'])->middleware('throttle:10,1');

    // Password reset email link → generates action_token → redirects browser
    Route::get('/auth/password/reset', [PasswordResetController::class, 'redirect'])
        ->name('password.reset')
        ->middleware('signed');

    // ── Social auth (public) ──────────────────────────────────────────────────

    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirectToProvider'])->middleware('throttle:20,1');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback'])->middleware('throttle:20,1');

    // ── Public invitation accept flow (rate-limited, no auth) ────────────────

    Route::get('/invitations/{token}', [AcceptInvitationController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/invitations/{token}/accept', [AcceptInvitationController::class, 'accept'])->middleware('throttle:5,1');

    // ── Authenticated routes ──────────────────────────────────────────────────

    Route::middleware('auth:sanctum')->group(function (): void {

        // Current user shortcut
        Route::get('/user', [UserController::class, 'me']);

        // Auth actions (require session)
        Route::post('/auth/verify-password', [AuthController::class, 'verifyPassword']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // 2FA management
        Route::post('/auth/setup-2fa', [TwoFactorController::class, 'setup']);
        Route::post('/auth/confirm-2fa', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/disable-2fa/send', [TwoFactorController::class, 'disableSend']);
        Route::post('/auth/disable-2fa/confirm', [TwoFactorController::class, 'disableConfirm']);

        // Contact verification
        Route::post('/auth/verification/send', [VerificationController::class, 'send']);
        Route::post('/auth/verification/verify', [VerificationController::class, 'verify']);

        // Device / session management
        Route::get('/auth/devices', [DeviceController::class, 'index']);
        Route::delete('/auth/devices/{id}', [DeviceController::class, 'destroy']);
        Route::delete('/auth/devices', [DeviceController::class, 'logoutOtherDevices']);

        // Social account linking (authenticated user)
        Route::get('/auth/{provider}/link/redirect', [SocialAuthController::class, 'redirectToLink']);
        Route::get('/auth/{provider}/link/callback', [SocialAuthController::class, 'handleLinkCallback']);
        Route::delete('/auth/{provider}/unlink', [SocialAuthController::class, 'unlink']);

        // Profile
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/language', [ProfileController::class, 'updateLanguage']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::put('/profile/appearance', [ProfileController::class, 'updateAppearance']);

        // ── Admin-only routes (role:admin) ────────────────────────────────────

        Route::middleware('role:admin')->group(function (): void {

            // User statistics + bulk actions (before apiResource to avoid route collision)
            Route::get('/users/statistics', [UserController::class, 'statistics']);
            Route::delete('/users/bulk', [UserController::class, 'bulkDestroy']);
            Route::post('/users/bulk/change-role', [UserController::class, 'bulkChangeRole']);
            Route::post('/users/bulk/send-password-reset', [UserController::class, 'bulkSendPasswordReset']);
            Route::post('/users/bulk/deactivate', [UserController::class, 'bulkDeactivate']);

            // Per-user admin lifecycle actions
            Route::get('/users/{user}/sessions', [UserController::class, 'sessions']);
            Route::post('/users/{user}/lock', [UserController::class, 'lock']);
            Route::post('/users/{user}/unlock', [UserController::class, 'unlock']);
            Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('/users/{user}/activate', [UserController::class, 'activate']);
            Route::post('/users/{user}/send-password-reset', [UserController::class, 'sendPasswordReset'])->middleware('throttle:5,1');
            Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->middleware('throttle:5,1');
            Route::post('/users/{user}/revoke-sessions', [UserController::class, 'revokeSessions']);
            Route::patch('/users/{user}/role', [UserController::class, 'changeRole']);

            // Hard/restore require withTrashed() binding
            Route::delete('/users/{user}/permanent', [UserController::class, 'permanentDelete'])->withTrashed();
            Route::post('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed();

            // Invitation admin actions
            Route::post('/users/invite', [UserController::class, 'invite']);
            Route::post('/users/{user}/resend-invitation', [UserController::class, 'resendInvitation'])->middleware('throttle:5,1');
            Route::post('/users/{user}/revoke-invitation', [UserController::class, 'revokeInvitation']);

            // Standard CRUD (index, store, show, update, destroy)
            Route::apiResource('users', UserController::class);
        });
    });
});

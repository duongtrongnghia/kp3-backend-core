<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Profile\ProfileData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProfileService
{
    /**
     * Update user profile, optionally replacing/deleting avatar.
     * File I/O runs outside transaction; DB save inside try/catch with cleanup.
     */
    public function update(User $user, ProfileData $data, mixed $avatar = null): User
    {
        $updateData = $data->toArray();
        $oldAvatar = $user->avatar;
        $newAvatarPath = null;

        if ($data->deleteAvatar && $oldAvatar) {
            $updateData['avatar'] = null;
        }

        if ($avatar) {
            $newAvatarPath = $avatar->store('avatars', 'public');
            $updateData['avatar'] = $newAvatarPath;
        }

        try {
            if (isset($updateData['email']) && $updateData['email'] !== $user->email) {
                $user->email_verified_at = null;
            }

            if (isset($updateData['phone']) && $updateData['phone'] !== $user->phone) {
                $user->phone_verified_at = null;
            }

            $user->fill($updateData);
            $user->save();
        } catch (Throwable $e) {
            if ($newAvatarPath) {
                Storage::disk('public')->delete($newAvatarPath);
            }
            throw $e;
        }

        if ($oldAvatar && ($data->deleteAvatar || $avatar)) {
            Storage::disk('public')->delete($oldAvatar);
        }

        // AuditLog removed.

        return $user->fresh() ?? $user;
    }

    /**
     * Update the user's preferred language.
     * Folded from UpdateLanguageAction.
     */
    public function updateLanguage(User $user, string $language): User
    {
        $user->update(['language' => $language]);

        return $user->fresh() ?? $user;
    }

    /**
     * Replace the user's password with a new bcrypt hash.
     * Folded from UpdatePasswordAction.
     */
    public function updatePassword(User $user, string $password): void
    {
        $user->update(['password' => Hash::make($password)]);
    }
}

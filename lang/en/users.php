<?php

declare(strict_types=1);

return [
    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'locked' => 'Locked',
        'pending_invite' => 'Invitation Pending',
    ],
    'errors' => [
        'no_permission' => 'You do not have permission for this action.',
        'cannot_self_modify' => 'You cannot perform this action on yourself.',
        'target_higher_role' => 'Target user has equal or higher role than you.',
        'no_reset_channel' => 'User has no email or phone for password reset.',
        'invalid_verification_channel' => 'Invalid verification channel (must be email or phone).',
        'no_verification_identifier' => 'User has no :channel to send verification to.',
        'must_soft_delete_first' => 'Must deactivate the account before permanently deleting.',
        'not_trashed' => 'Account is not soft-deleted — cannot restore.',
    ],
    'success' => [
        'locked' => 'Account locked.',
        'unlocked' => 'Account unlocked.',
        'deactivated' => 'Account deactivated.',
        'activated' => 'Account reactivated.',
        'password_reset_sent' => 'Password reset link sent.',
        'verification_resent' => 'Verification link resent.',
        'sessions_revoked' => 'Revoked :count sessions.',
        'role_changed' => 'Role changed.',
        'permanently_deleted' => 'Permanently deleted.',
        'restored' => 'Account restored.',
        'bulk_done' => 'Done.',
    ],
    'validation' => [
        'email_or_phone_required' => 'At least an email or phone is required.',
    ],
];

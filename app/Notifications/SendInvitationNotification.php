<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation email sent to a new admin user.
 *
 * No SMS fallback — invitations are admin-panel onboarding, always email.
 * No OTP code — acceptance is a signed URL flow (single-step UX on mobile).
 * Link expires in 48h; copy states this explicitly.
 */
class SendInvitationNotification extends Notification
{
    public function __construct(
        public readonly string $rawToken,
        public readonly string $firstName,
        public readonly string $acceptUrl,
        public readonly string $tenantName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('api.invitation.subject', ['tenant' => $this->tenantName]))
            ->greeting(__('api.invitation.greeting', ['name' => $this->firstName]))
            ->line(__('api.invitation.intro', ['tenant' => $this->tenantName]))
            ->action(__('api.invitation.cta'), $this->acceptUrl)
            ->line(__('api.invitation.expiry'))
            ->line(__('api.invitation.ignore'));
    }
}

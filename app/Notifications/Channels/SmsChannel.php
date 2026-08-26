<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Interfaces\SmsProviderInterface;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(protected SmsProviderInterface $smsProvider) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $message = $notification->toSms($notifiable);

        // Accept on-demand route 'sms' or fall back to $notifiable->phone.
        $phone = null;
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $phone = $notifiable->routeNotificationFor('sms');
        }
        if (! $phone && isset($notifiable->phone)) {
            $phone = $notifiable->phone;
        }

        if ($phone && $message) {
            $this->smsProvider->send((string) $phone, (string) $message);
        }
    }
}

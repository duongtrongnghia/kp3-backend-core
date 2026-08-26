<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

class SendEmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $code,
        public readonly string $channelType = 'mail',
        public readonly ?string $verifyUrl = null,
    ) {
        $this->afterCommit = true;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelType === 'sms' ? [SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $codeHtml = '<div style="font-size:24px;font-weight:bold;color:#4F46E5;text-align:center;padding:10px;">'
            .htmlspecialchars($this->code, ENT_QUOTES, 'UTF-8').'</div>';

        $mail = (new MailMessage)
            ->subject(__('api.notification.verification_subject'))
            ->greeting(__('api.notification.verification_greeting'))
            ->line(__('api.notification.verification_intro'))
            ->line(__('api.notification.otp_code_label'))
            ->line(new HtmlString($codeHtml));

        if ($this->verifyUrl) {
            $mail->action(__('api.notification.verification_action'), $this->verifyUrl);
        }

        return $mail
            ->line(__('api.notification.verification_expiry'))
            ->line(__('api.notification.otp_ignore'));
    }

    public function toSms(object $notifiable): string
    {
        return __('api.notification.verification_sms', [
            'app' => config('app.name', 'App'),
            'code' => $this->code,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Email verification notification failed', [
            'notification' => self::class,
            'channel' => $this->channelType,
            'exception' => $exception->getMessage(),
        ]);
    }
}

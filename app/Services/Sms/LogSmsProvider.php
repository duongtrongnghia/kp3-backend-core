<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Interfaces\SmsProviderInterface;
use Illuminate\Support\Facades\Log;

class LogSmsProvider implements SmsProviderInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::info("SMS SENT -> To: [{$phoneNumber}] | Message: [{$message}]");

        return true;
    }
}

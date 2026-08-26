<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Interfaces\SmsProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Twilio SMS provider.
 *
 * To enable:
 *   1. composer require twilio/sdk
 *   2. Set TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM in .env
 *   3. Set SMS_PROVIDER=twilio in .env
 *   4. Uncomment the implementation block below.
 */
class TwilioSmsProvider implements SmsProviderInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        /*
        try {
            $sid   = config('services.twilio.sid');
            $token = config('services.twilio.token');
            $from  = config('services.twilio.from');

            if (!$sid || !$token || !$from) {
                Log::error('Twilio config missing (TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM)');
                return false;
            }

            $client = new \Twilio\Rest\Client($sid, $token);
            $client->messages->create($phoneNumber, [
                'from' => $from,
                'body' => $message,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Twilio SMS error: ' . $e->getMessage());
            return false;
        }
        */

        Log::warning("Twilio SMS provider not enabled. SMS to [{$phoneNumber}] logged only.");

        return true;
    }
}

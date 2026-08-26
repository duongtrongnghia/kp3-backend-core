<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Provider
    |--------------------------------------------------------------------------
    |
    | Set SMS_PROVIDER=twilio in .env to use Twilio.
    | Default is 'log' — logs SMS messages to the Laravel log (dev/test safe).
    |
    | Supported: "log", "twilio"
    |
    */
    'provider' => env('SMS_PROVIDER', 'log'),
];

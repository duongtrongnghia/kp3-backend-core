<?php

declare(strict_types=1);

namespace App\Interfaces;

interface SmsProviderInterface
{
    /**
     * Send an SMS message to a phone number.
     */
    public function send(string $phoneNumber, string $message): bool;
}

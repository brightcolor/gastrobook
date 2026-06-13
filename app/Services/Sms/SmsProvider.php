<?php

declare(strict_types=1);

namespace App\Services\Sms;

interface SmsProvider
{
    /**
     * Send a text message. Returns true when the provider accepted it.
     *
     * @param  string  $to  Recipient in international format (e.g. 491701234567)
     * @param  string  $text  Message body
     */
    public function send(string $to, string $text): bool;

    /**
     * Lightweight credential/connectivity check (e.g. balance lookup).
     */
    public function testConnection(): bool;
}

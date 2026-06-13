<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * seven.io (formerly sms77) SMS gateway via REST API, no SDK dependency.
 * German provider, GDPR-compliant. Docs: https://docs.seven.io
 *
 * Auth: header "X-Api-Key: <key>". The /sms endpoint returns JSON when
 * Accept: application/json is set; "success" == "100" means accepted.
 */
class SevenIoProvider implements SmsProvider
{
    private const API_BASE = 'https://gateway.seven.io/api';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $text): bool
    {
        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])
            ->asForm()
            ->timeout(15)
            ->post(self::API_BASE.'/sms', array_filter([
                'to' => $to,
                'text' => $text,
                'from' => $this->senderId !== '' ? $this->senderId : null,
            ]));

        if (! $response->successful()) {
            Log::warning('seven.io send failed (HTTP)', ['status' => $response->status()]);

            return false;
        }

        // JSON response: { "success": "100", "messages": [...] }
        $success = (string) $response->json('success', '');
        if ($success === '100') {
            return true;
        }

        // Fallback: classic plain-text response where the first line is the code
        $firstLine = trim(strtok($response->body(), "\n") ?: '');
        if ($firstLine === '100') {
            return true;
        }

        Log::warning('seven.io send rejected', ['code' => $success !== '' ? $success : $firstLine]);

        return false;
    }

    public function testConnection(): bool
    {
        $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
            ->timeout(10)
            ->get(self::API_BASE.'/balance');

        if (! $response->successful()) {
            return false;
        }

        // Balance endpoint returns a numeric amount (e.g. "12.345")
        return is_numeric(trim($response->body()));
    }
}

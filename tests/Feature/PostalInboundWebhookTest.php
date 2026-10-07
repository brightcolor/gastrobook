<?php

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PostalInboundWebhookTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCx8NU4DaI3KEuK
        dz1JXPfoHUzDlrhskgrWN6CGtHvkrhkwuUBhbNeLUi0OcFOAU9aj++Y9jN+9jW97
        4qNBCW4fm//teL0cQSh8v1WMpByo9MDGjJajrZRPfM7xmRp1MlgXftwp0yxR7Jnx
        MkUc3efh3hcT5s0TlqH1YlQh2tHkpXlcAcdbFtP27rng8dQnmN/8+dU0mJrfkMYD
        xEPb8//PfJdZl8flMq4rzukgm8QVUwRWV/XwBMqnXioFFRUl5ADEWxcf6noYJFQp
        9y70SZDqxQlMILQi6hWyl3+szHd40ir8m0XPBXTfqlOhQAwEu8UhgK5bQmzi7Rdg
        cbX17sGNAgMBAAECggEAF9+F28Vd1CSgg0KdgwWUGnIukGHc+xlaQYSpEX7kIw7b
        QQGPClh9+qqFx6rOU9NBDYiISrhnHu6mMcAlwwiu26gkXV9A5VTgnQwBQE3sV8au
        mR+CPmeAFGyxnxG5eQEjdyjar58uEmJkrPWKTDssFyOi2QAILO6daryh06ugqW+e
        AX2WRiHPPjR08EFV7fiNKLbyasJxG0X9fuljGCM1e2QrvLNu5rbLwMBHVfjO/GxB
        QfkJY0uFD+M4Uo0goJBRl/w4vRZ71KRXVhFbQ9GeUkeJdlCWs/Ye1VUjU5Z84B06
        drrPIWnvZgLS3UKJy/sTXHCkBlDc9Lj4kLN5GitJiQKBgQDeuXP/1raUHFSOQlm+
        73gppcxr9pOhRGMgH+sPRUdUZvM64NHFX9kVMwjk+MA2vY4ElO7nVl4tInAYAMUz
        hJsblSd5BCm1K/VVsILP4/LRHxCQ3bbjf1DV5zCUdImiiCgXORsgzSHIQ+8pqKJh
        ZZ3xxj5frYk6f8Jv8Q2PC5pMDwKBgQDMhomX+HIu45kM1XBnOk6fhCXE9q6Bwcq7
        KBuHlKWigEQNvQYw9ws5rmmVLkc2J9RIuxzpDSdGSb2FAk803pzR67LZaDF5936i
        Zn15hj++f4s2STPiK3cBivYWipqrIfGRNmQK89ge+WrRxOfDUMVzfA/iv/teIWNL
        SRMU6cZsowKBgQDCNh6TeWwNrMKCphLR7sjuMBgYEJRc7GAvdAWpdDSlwXvY3I+u
        t0x5Mt5PoyUg1puPHTtRWDuYyc3K8GkE6l3CaIZZ/SpNQ76TcO4wT0m91oPAfsTq
        jWs0insPCKu3oVisH2yrZpRNqAdVYSnvGgfm+oILNixSaXNn319+W5S5OwKBgF1V
        +GzWAKXNUAc/UHCLd13snJ/qQ3EL00zd3NJez8f86RGr9ata0lCce6qM2Aqq2oHm
        gicIzaeR918/0o26Ga7i9Vep6QpUHAJY62IOFgEFi65WccsBMuoNVIis8DCw6ODw
        BW/KIBLimBDq3ymPLsypDUbZfglTC1FMI90jYl4pAoGAUW9zYTL3SrfagjL3I5LB
        df5VsSWZG9PggMc8To5FDs5rLutMIec0FJQJAEKfeqc+9t4JTGK9FuyOGun9bPbj
        C2QElBF+3xdJnXtC/y1wi8fW+fkfPgfjA1/xNQbNxhDLRM13bWK6TkRUFeST7nXl
        bwU3TgtayseBAv+cshyV8rQ=
        -----END PRIVATE KEY-----
        PEM;

    private const PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsfDVOA2iNyhLinc9SVz3
        6B1Mw5a4bJIK1jeghrR75K4ZMLlAYWzXi1ItDnBTgFPWo/vmPYzfvY1ve+KjQQlu
        H5v/7Xi9HEEofL9VjKQcqPTAxoyWo62UT3zO8ZkadTJYF37cKdMsUeyZ8TJFHN3n
        4d4XE+bNE5ah9WJUIdrR5KV5XAHHWxbT9u654PHUJ5jf/PnVNJia35DGA8RD2/P/
        z3yXWZfH5TKuK87pIJvEFVMEVlf18ATKp14qBRUVJeQAxFsXH+p6GCRUKfcu9EmQ
        6sUJTCC0IuoVspd/rMx3eNIq/JtFzwV036pToUAMBLvFIYCuW0Js4u0XYHG19e7B
        jQIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
        config(['swayy.guest_mail_relay.inbound_public_key' => $this->pem(self::PUBLIC_KEY)]);
    }

    private function pem(string $pem): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($pem))));
    }

    private function sign(string $payload): string
    {
        openssl_sign($payload, $sig, $this->pem(self::PRIVATE_KEY), OPENSSL_ALGO_SHA256);

        return base64_encode($sig);
    }

    private function payload(string $rcpt): string
    {
        $raw = "From: Gast <gast@example.test>\r\nSubject: Re: Buchung\r\nMessage-ID: <w1@example.test>\r\n\r\nHallo.";

        return (string) json_encode(['id' => 1, 'rcpt_to' => $rcpt, 'mail_from' => 'gast@example.test', 'message' => base64_encode($raw)]);
    }

    private function postWebhook(string $body, string $signature)
    {
        return $this->call('POST', '/webhooks/postal', [], [], [], [
            'HTTP_X-Postal-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_valid_signed_reply_is_accepted_and_forwarded(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->update(['email' => 'betrieb@example.test']);
        $reservation = Reservation::factory()->create(['location_id' => $setup['location']->id])->load('tenant');
        $body = $this->payload(GuestReplyAddress::forReservation($reservation));

        $this->postWebhook($body, $this->sign($body))->assertOk();

        Mail::assertQueued(ForwardedGuestReplyMail::class);
        $this->assertDatabaseHas('guest_mail_replies', ['reservation_id' => $reservation->id, 'match_status' => 'matched']);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->postWebhook($this->payload('x+y.z@antwort.swayy.de'), 'wrong')->assertStatus(401);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $body = 'not json';
        $this->postWebhook($body, $this->sign($body))->assertStatus(400);
    }
}

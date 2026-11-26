<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\Mail\MailDeliveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Zustellstatus der Mails: Message-ID im Versandprotokoll, Meldungen von
 * Postal über den Webhook, Warten auf der Buchungsseite und die Liste im Admin.
 */
class MailDeliveryTrackingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Dasselbe Testschlüsselpaar wie in PostalInboundWebhookTest, nur für Tests. */
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

    private static function pem(string $pem): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($pem))));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'swayy.guest_mail_relay.inbound_public_key' => self::pem(self::PUBLIC_KEY),
            'swayy.mail_delivery.wait_for_delivery' => true,
            'mail.from.address' => 'buchung@mail.example.test',
        ]);
    }

    // ── Helfer ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function postDelivery(array $data, bool $sign = true)
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        openssl_sign($body, $signature, self::pem(self::PRIVATE_KEY), OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/webhooks/postal/delivery', [], [], [], [
            'HTTP_X-Postal-Signature-256' => $sign ? base64_encode($signature) : base64_encode('falsch'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** @return array<string, mixed> So schickt Postal MessageSent, MessageDelayed, MessageDeliveryFailed und MessageHeld. */
    private function postalEvent(string $event, string $messageId, float $timestamp, string $details = '', string $output = ''): array
    {
        return [
            'event' => $event,
            'timestamp' => $timestamp,
            'uuid' => 'c0ffee00-0000-4000-8000-000000000001',
            'payload' => [
                'message' => [
                    'id' => 4711,
                    'token' => 'abc123',
                    'direction' => 'outgoing',
                    'message_id' => $messageId,
                    'to' => 'gast@example.test',
                    'from' => 'buchung@mail.example.test',
                    'subject' => 'Test',
                    'timestamp' => $timestamp - 1,
                ],
                'status' => match ($event) {
                    'MessageSent' => 'Sent',
                    'MessageDelayed' => 'SoftFail',
                    'MessageHeld' => 'Held',
                    default => 'HardFail',
                },
                'details' => $details,
                'output' => $output,
                'sent_with_ssl' => true,
                'timestamp' => $timestamp,
                'time' => 0.4,
            ],
        ];
    }

    /** @param  array<string, mixed>  $setup */
    private function makeReservation(array $setup, string $email = 'gast@example.test'): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        return Reservation::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Requested,
            'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
            'guest_email_snapshot' => $email,
        ]);
    }

    private function openLog(Reservation $reservation, string $template = 'guest_verify', string $status = NotificationLog::STATUS_SENT): NotificationLog
    {
        $log = app(MailDeliveryLog::class)->open(new TemplatedMail('Betreff', 'Text'), [
            'tenant_id' => $reservation->tenant_id,
            'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id,
            'template_key' => $template,
            'recipient' => $reservation->guest_email_snapshot,
            'subject' => 'Betreff',
        ]);
        $log->update(['status' => $status]);

        return $log->refresh();
    }

    /** Ist die Erfolgsmeldung der Bestätigungsseite verborgen? */
    private function assertSuccessHidden(string $html, bool $hidden): void
    {
        $matches = (bool) preg_match('/data-delivery-success\s+hidden/', $html);
        $this->assertSame($hidden, $matches, $hidden ? 'Die Erfolgsmeldung steht schon da.' : 'Die Erfolgsmeldung ist verborgen.');
    }

    private function confirmationUrl(Reservation $reservation): string
    {
        return '/reservation/'.$reservation->code.'/confirmed/'.$reservation->manage_token;
    }

    private function statusUrl(Reservation $reservation): string
    {
        return '/reservation/'.$reservation->code.'/mail-status/'.$reservation->manage_token;
    }

    // ── Versand ──────────────────────────────────────────────────────────

    public function test_the_verification_mail_carries_the_message_id_of_its_log_entry(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => true]);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);
        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Testgast',
            'email' => 'gast@example.test',
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        $log = NotificationLog::withoutGlobalScopes()->where('template_key', 'guest_verify')->firstOrFail();
        $this->assertSame('gast@example.test', $log->recipient);
        $this->assertStringEndsWith('@mail.example.test', (string) $log->message_id);
        // Der Mailserver (hier: array) hat angenommen → übergeben.
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn (SentMessage $m) => $m->getOriginalMessage()->getHeaders()->get('Message-ID')?->getBodyAsString())
            ->all();
        $this->assertContains('<'.$log->message_id.'>', $sent);
    }

    public function test_a_failing_mail_server_does_not_break_the_booking(): void
    {
        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => true]);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);
        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Testgast',
            'email' => 'gast@example.test',
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        $log = NotificationLog::withoutGlobalScopes()->where('template_key', 'guest_verify')->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('nicht erreichbar', (string) $log->error);

        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->get($this->statusUrl($reservation))
            ->assertOk()
            ->assertJson(['state' => 'failed']);
    }

    public function test_a_queued_mail_that_finally_fails_is_marked_as_failed(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $mail = new TemplatedMail('Betreff', 'Text');
        $log = app(MailDeliveryLog::class)->open($mail, [
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
            'template_key' => 'reservation_confirmed',
            'recipient' => 'gast@example.test',
        ]);

        $mail->failed(new RuntimeException('SMTP 421 Service not available'));

        $log->refresh();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('SMTP 421', (string) $log->error);
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    public function test_postal_reports_delivery(): void
    {
        $setup = $this->createTenantSetup();
        $log = $this->openLog($this->makeReservation($setup));

        // Postal schickt die Message-ID ohne spitze Klammern.
        $this->postDelivery($this->postalEvent('MessageSent', $log->message_id, (float) time(), 'Message sent by SMTP to mx.example.test', '250 2.0.0 OK'))
            ->assertOk()
            ->assertJson(['result' => NotificationLog::STATUS_DELIVERED]);

        $log->refresh();
        $this->assertSame(NotificationLog::STATUS_DELIVERED, $log->status);
        $this->assertStringContainsString('250 2.0.0 OK', (string) $log->status_detail);
        $this->assertNull($log->error);
    }

    public function test_a_bounce_after_delivery_wins_and_an_older_delay_does_not(): void
    {
        $setup = $this->createTenantSetup();
        $log = $this->openLog($this->makeReservation($setup));
        $now = (float) time();

        $this->postDelivery($this->postalEvent('MessageSent', $log->message_id, $now - 60))->assertOk();
        $this->postDelivery([
            'event' => 'MessageBounced',
            'timestamp' => $now,
            'payload' => [
                'original_message' => ['message_id' => '<'.strtoupper($log->message_id).'>'],
                'bounce' => ['message_id' => 'bounce@mx.example.test', 'subject' => 'Mail delivery failed'],
            ],
        ])->assertJson(['result' => NotificationLog::STATUS_BOUNCED]);

        // Eine verspätete, ältere Meldung ändert nichts mehr.
        $this->postDelivery($this->postalEvent('MessageDelayed', $log->message_id, $now - 120))
            ->assertJson(['result' => 'stale']);

        $log->refresh();
        $this->assertSame(NotificationLog::STATUS_BOUNCED, $log->status);
        $this->assertStringContainsString('Mail delivery failed', (string) $log->error);
    }

    public function test_unknown_messages_other_events_and_bad_signatures(): void
    {
        $this->postDelivery($this->postalEvent('MessageSent', 'fremd@example.test', (float) time()))
            ->assertOk()->assertJson(['result' => 'unknown']);

        $this->postDelivery(['event' => 'MessageLinkClicked', 'payload' => []])
            ->assertOk()->assertJson(['result' => 'ignored']);

        $this->postDelivery(['payload' => []])->assertStatus(400);

        $this->postDelivery($this->postalEvent('MessageSent', 'x@example.test', (float) time()), sign: false)
            ->assertStatus(401)
            ->assertJsonPath('error', fn (string $e) => str_contains($e, 'Signatur'));
    }

    // ── Buchungsseite ────────────────────────────────────────────────────

    public function test_the_confirmation_page_waits_until_postal_confirms_delivery(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $log = $this->openLog($reservation);

        $page = $this->get($this->confirmationUrl($reservation))
            ->assertOk()
            ->assertSee('bitte bleiben Sie auf dieser Seite', false)
            ->assertSee(route('booking.mail-status', ['code' => $reservation->code, 'token' => $reservation->manage_token]), false);
        $this->assertSuccessHidden($page->getContent(), true);

        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'pending']);

        $this->postDelivery($this->postalEvent('MessageSent', $log->message_id, (float) time()))->assertOk();

        $this->getJson($this->statusUrl($reservation))
            ->assertJson(['state' => 'delivered'])
            ->assertHeader('Cache-Control');
        $this->assertSuccessHidden($this->get($this->confirmationUrl($reservation))->assertOk()->getContent(), false);
    }

    public function test_the_guest_reads_why_the_mail_did_not_arrive_and_what_to_do(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['phone' => '+49 451 999']);
        $reservation = $this->makeReservation($setup, 'tippfehler@example.test');
        $log = $this->openLog($reservation);

        $this->postDelivery($this->postalEvent('MessageDeliveryFailed', $log->message_id, (float) time(),
            'Permanent SMTP delivery error', '550 5.1.1 The email account that you tried to reach does not exist'))->assertOk();

        $json = $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'failed'])->json();
        $this->assertStringContainsString('tippfehler@example.test', $json['message']);
        $this->assertStringContainsString('Diese Adresse gibt es', $json['message']);
        $this->assertStringContainsString('noch nicht fest', $json['message']);
        $this->assertStringContainsString('+49 451 999', $json['message']);
        $this->assertSame('M-'.$log->id, $json['reference']);

        $this->get($this->confirmationUrl($reservation))
            ->assertOk()
            ->assertSee('Unsere E-Mail an Sie ist nicht angekommen.');
    }

    public function test_patterns_and_window_come_from_the_settings(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $log = $this->openLog($reservation, 'reservation_confirmed');
        $log->update(['status' => NotificationLog::STATUS_FAILED, 'status_detail' => 'Fehlercode XY-42']);

        $this->getJson($this->statusUrl($reservation))
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'hat sie abgelehnt') && str_contains($m, 'ist gespeichert'));

        config(['swayy.mail_delivery.unknown_address_patterns' => ['xy-42']]);
        $this->getJson($this->statusUrl($reservation))
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Diese Adresse gibt es'));

        // Älter als das eingestellte Fenster: Die Seite wartet nicht mehr.
        config(['swayy.mail_delivery.recent_minutes' => 1]);
        NotificationLog::withoutGlobalScopes()->whereKey($log->id)->update(['created_at' => now()->subMinutes(2)]);
        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'none']);
    }

    public function test_without_the_switch_the_page_shows_success_at_once(): void
    {
        config(['swayy.mail_delivery.wait_for_delivery' => false]);
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $this->openLog($reservation);

        $page = $this->get($this->confirmationUrl($reservation))
            ->assertOk()
            ->assertDontSee('id="mail-delivery"', false);
        $this->assertSuccessHidden($page->getContent(), false);
        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'none']);
    }

    public function test_the_page_uses_du_when_the_location_does(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['guest_address' => 'du']);
        $reservation = $this->makeReservation($setup);
        $this->openLog($reservation);

        $this->get($this->confirmationUrl($reservation))
            ->assertOk()
            ->assertSee('bitte bleib auf dieser Seite', false);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function test_the_reservation_lists_its_mails_with_their_state(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->makeReservation($setup);
        $verify = $this->openLog($reservation);
        $this->openLog($reservation, 'owner_new_reservation', NotificationLog::STATUS_QUEUED);
        $this->postDelivery($this->postalEvent('MessageHeld', $verify->message_id, (float) time(), 'Recipient is on the suppression list'));
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('E-Mails')
            ->assertSee('Link zur E-Mail-Bestätigung')
            ->assertSee('Zurückgehalten')
            ->assertSee('suppression list')
            ->assertSee('Hinweis an den Betrieb: neue Reservierung')
            ->assertSee('Wartet auf Versand')
            // Der Hinweis an den Betrieb ist noch unterwegs, aber nur die Mail
            // an den Gast hält die Seite fest, und die ist entschieden.
            ->assertSee('data-waiting="0"', false);

        $this->actingAs($owner)->getJson('/admin/reservations/'.$reservation->id.'/mail-status')
            ->assertOk()
            ->assertJsonPath('waiting', false)
            ->assertJsonCount(2, 'rows')
            ->assertJsonFragment(['label' => 'Zurückgehalten', 'tone' => 'bad']);
    }

    public function test_only_the_guest_mail_decides_whether_a_page_waits(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->makeReservation($setup);
        $guestMail = $this->openLog($reservation);
        $operatorMail = $this->openLog($reservation, 'owner_new_reservation');
        $this->clearTenantContext();

        // Beide unterwegs: Es wird gewartet, wegen der Gastmail.
        $this->actingAs($owner)->getJson('/admin/reservations/'.$reservation->id.'/mail-status')
            ->assertJsonPath('waiting', true);
        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'pending']);

        // Gastmail zugestellt, Mail an den Betrieb abgewiesen: Für den Gast
        // ist der Vorgang erfolgreich, im Admin wartet nichts mehr.
        $this->postDelivery($this->postalEvent('MessageSent', $guestMail->message_id, (float) time()))->assertOk();
        $this->postDelivery($this->postalEvent('MessageDeliveryFailed', $operatorMail->message_id, (float) time(), 'Permanent SMTP delivery error'))->assertOk();

        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'delivered']);
        $this->actingAs($owner)->getJson('/admin/reservations/'.$reservation->id.'/mail-status')
            ->assertJsonPath('waiting', false)
            ->assertJsonFragment(['label' => 'Nicht zustellbar', 'tone' => 'bad']);
    }

    public function test_operator_templates_come_from_the_settings(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $this->openLog($reservation, 'team_digest');

        // Ab Werk gilt team_digest als Mail an den Gast: Die Seite wartet.
        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'pending']);

        config(['swayy.mail_delivery.operator_templates' => ['owner_new_reservation', 'team_digest']]);
        $this->getJson($this->statusUrl($reservation))->assertJson(['state' => 'none']);
    }

    public function test_the_list_limit_comes_from_the_settings(): void
    {
        config(['swayy.mail_delivery.list_max' => 5]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->makeReservation($setup);
        foreach (range(1, 7) as $i) {
            $this->openLog($reservation, 'reservation_reminder');
        }
        $this->clearTenantContext();

        $this->actingAs($owner)->getJson('/admin/reservations/'.$reservation->id.'/mail-status')
            ->assertOk()
            ->assertJsonCount(5, 'rows');
    }

    public function test_another_tenant_cannot_read_the_mail_status(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->makeReservation($setup);
        $this->openLog($reservation);

        $other = $this->createTenantSetup();
        $stranger = $this->createMember($other['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $status = $this->actingAs($stranger)
            ->getJson('/admin/reservations/'.$reservation->id.'/mail-status')
            ->status();
        $this->assertContains($status, [403, 404]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Führt das Versandprotokoll einer Mail vom Anlegen bis zur Meldung von Postal.
 *
 * 1. open() legt den Eintrag mit eigener Message-ID an und gibt sie der Mail mit.
 * 2. markSent() setzt ihn auf „übergeben“, sobald der Mailserver die Mail annimmt.
 * 3. applyPostalEvent() übernimmt Zustellung, Verzögerung, Rückhalt und Abweisung.
 *
 * Nur Postal setzt status_changed_at. Die Uhr von Postal ordnet seine Meldungen
 * untereinander; eine abweichende Uhr dieses Servers verdrängt sie so nie.
 */
final class MailDeliveryLog
{
    /** Postal-Ereignis → Zustand im Protokoll. */
    private const POSTAL_EVENTS = [
        'MessageSent' => NotificationLog::STATUS_DELIVERED,
        'MessageDelayed' => NotificationLog::STATUS_DELAYED,
        'MessageDeliveryFailed' => NotificationLog::STATUS_FAILED,
        'MessageHeld' => NotificationLog::STATUS_HELD,
        'MessageBounced' => NotificationLog::STATUS_BOUNCED,
    ];

    /**
     * Legt den Eintrag an und verbindet die Mail damit.
     *
     * @param  array<string, mixed>  $attributes  tenant_id, location_id, reservation_id, template_key, recipient, subject
     */
    public function open(Mailable $mail, array $attributes): NotificationLog
    {
        $log = NotificationLog::withoutGlobalScopes()->create([
            ...$attributes,
            'channel' => 'mail',
            'status' => NotificationLog::STATUS_QUEUED,
            'message_id' => $this->newMessageId(),
        ]);

        if (method_exists($mail, 'trackDelivery')) {
            $mail->trackDelivery($log);
        }

        return $log;
    }

    /**
     * Legt den Eintrag an und stellt die Mail in die Warteschlange. Klappt das
     * Einstellen nicht, steht der Eintrag auf „Nicht zustellbar“, und der
     * Fehler geht weiter an den Aufrufer.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function queue(PendingMail $to, Mailable $mail, array $attributes): NotificationLog
    {
        $log = $this->open($mail, $attributes);

        try {
            $to->queue($mail);
        } catch (Throwable $e) {
            $this->markFailed($log->id, $e);
            throw $e;
        }

        return $log;
    }

    /**
     * Legt den Eintrag an und verschickt die Mail sofort. Ein Fehler beim
     * Versand landet im Eintrag und im Fehlerprotokoll; der Aufrufer läuft
     * weiter und zeigt den Zustand aus dem Eintrag an.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function sendNow(PendingMail $to, Mailable $mail, array $attributes): NotificationLog
    {
        $log = $this->open($mail, $attributes);

        try {
            $to->send($mail);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($log->id, $e);
        }

        return $log->refresh();
    }

    /** Der Mailserver hat die Mail angenommen. */
    public function markSent(string $messageId): void
    {
        $id = self::normalizeMessageId($messageId);
        if ($id === '') {
            return;
        }

        NotificationLog::withoutGlobalScopes()
            ->where('message_id', $id)
            ->where('status', NotificationLog::STATUS_QUEUED)
            ->update(['status' => NotificationLog::STATUS_SENT]);
    }

    /** Der Versand ist gescheitert, bevor Postal die Mail übernommen hat. */
    public function markFailed(int $logId, Throwable $e): void
    {
        NotificationLog::withoutGlobalScopes()
            ->whereKey($logId)
            ->whereIn('status', [NotificationLog::STATUS_QUEUED, NotificationLog::STATUS_SENT])
            ->update([
                'status' => NotificationLog::STATUS_FAILED,
                'error' => $this->clip('Der Mailserver hat die Mail nicht angenommen oder war nicht erreichbar ('
                    .class_basename($e).': '.$e->getMessage().').'),
            ]);
    }

    /**
     * Übernimmt eine Meldung von Postal.
     *
     * @param  array<string, mixed>  $data  der ganze Webhook-Body
     * @return string ignored (kein Zustellereignis), unknown (Mail nicht von hier), stale (ältere Meldung) oder der neue Zustand
     */
    public function applyPostalEvent(array $data): string
    {
        $event = (string) ($data['event'] ?? '');
        $status = self::POSTAL_EVENTS[$event] ?? null;
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if ($status === null) {
            return 'ignored';
        }

        $message = $event === 'MessageBounced' ? ($payload['original_message'] ?? null) : ($payload['message'] ?? null);
        $messageId = self::normalizeMessageId(is_array($message) ? (string) ($message['message_id'] ?? '') : '');
        if ($messageId === '') {
            return 'unknown';
        }

        $at = $this->eventTime($payload['timestamp'] ?? ($data['timestamp'] ?? null));
        $detail = $this->clip($this->detailFor($event, $payload));

        $query = NotificationLog::withoutGlobalScopes()->where('message_id', $messageId);
        if (! (clone $query)->exists()) {
            return 'unknown';
        }

        // Bedingt geschrieben: Eine ältere Meldung, die nach einer neueren
        // eintrifft, ändert nichts. Eine Anweisung, keine Lücke zwischen Lesen
        // und Schreiben.
        $affected = $query
            ->where(fn ($q) => $q->whereNull('status_changed_at')->orWhere('status_changed_at', '<=', $at))
            ->update([
                'status' => $status,
                'status_detail' => $detail,
                'status_changed_at' => $at,
                'error' => in_array($status, NotificationLog::FAILED_STATUSES, true) ? $detail : null,
            ]);

        return $affected > 0 ? $status : 'stale';
    }

    /** Message-ID ohne spitze Klammern und Leerraum, klein geschrieben. */
    public static function normalizeMessageId(string $id): string
    {
        return mb_strtolower(trim($id, " \t\n\r\0\x0B<>"));
    }

    private function newMessageId(): string
    {
        $domain = (string) config('swayy.mail_delivery.message_id_domain');
        if ($domain === '') {
            $domain = Str::after((string) config('mail.from.address'), '@');
        }
        if ($domain === '' || ! str_contains($domain, '.')) {
            $domain = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        }

        return self::normalizeMessageId(Str::uuid()->toString().'@'.$domain);
    }

    private function eventTime(mixed $timestamp): CarbonImmutable
    {
        if (is_numeric($timestamp) && (float) $timestamp > 0) {
            return CarbonImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $timestamp)) ?: CarbonImmutable::now();
        }

        return CarbonImmutable::now();
    }

    /** @param  array<string, mixed>  $payload */
    private function detailFor(string $event, array $payload): string
    {
        if ($event === 'MessageBounced') {
            $bounce = is_array($payload['bounce'] ?? null) ? $payload['bounce'] : [];
            $subject = trim((string) ($bounce['subject'] ?? ''));

            return 'Rückläufer vom Mailserver des Empfängers'.($subject !== '' ? ': '.$subject : '.');
        }

        return trim(implode(' – ', array_filter([
            trim((string) ($payload['details'] ?? '')),
            trim((string) ($payload['output'] ?? '')),
        ])));
    }

    private function clip(string $text): string
    {
        return Str::limit($text, (int) config('swayy.mail_delivery.detail_max', 1000), '…');
    }
}

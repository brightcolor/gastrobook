<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\NotificationLog;
use App\Services\Mail\MailDeliveryLog;
use Illuminate\Mail\Mailables\Headers;
use Throwable;

/**
 * Verbindet eine Mail mit ihrem Eintrag im Versandprotokoll.
 *
 * Die Mail trägt die Message-ID des Eintrags. Darüber ordnen der Versand
 * (Ereignis MessageSent) und die Meldungen von Postal den Zustand zu. Scheitert
 * der Versand aus der Warteschlange endgültig, ruft Laravel failed() auf, und
 * der Eintrag steht auf „Nicht zustellbar“.
 */
trait TracksDelivery
{
    public ?int $deliveryLogId = null;

    public ?string $deliveryMessageId = null;

    public function trackDelivery(NotificationLog $log): static
    {
        $this->deliveryLogId = $log->id;
        $this->deliveryMessageId = $log->message_id;

        return $this;
    }

    /** Ergänzt die Kopfzeilen um die Message-ID des Protokolleintrags. */
    protected function withDeliveryHeaders(Headers $headers): Headers
    {
        if ($this->deliveryMessageId === null || $this->deliveryMessageId === '') {
            return $headers;
        }

        return new Headers(
            messageId: $this->deliveryMessageId,
            references: $headers->references,
            text: $headers->text,
        );
    }

    /** Laravel ruft das nach dem letzten gescheiterten Versuch aus der Warteschlange auf. */
    public function failed(Throwable $e): void
    {
        if ($this->deliveryLogId !== null) {
            app(MailDeliveryLog::class)->markFailed($this->deliveryLogId, $e);
        }
    }
}

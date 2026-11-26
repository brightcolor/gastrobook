<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TemplateLabels;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine verschickte Nachricht (Mail, SMS …) im Versandprotokoll.
 *
 * Zustand einer Mail: `queued` (wartet auf den Versand), `sent` (vom
 * Mailserver angenommen), danach meldet Postal `delivered`, `delayed`, `held`,
 * `failed` oder `bounced`.
 */
class NotificationLog extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_HELD = 'held';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BOUNCED = 'bounced';

    /** Die Mail ist unterwegs; das Ergebnis steht noch aus. */
    public const PENDING_STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_DELAYED];

    /** Die Mail hat den Empfänger nicht erreicht. */
    public const FAILED_STATUSES = [self::STATUS_HELD, self::STATUS_FAILED, self::STATUS_BOUNCED];

    private const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Wartet auf Versand',
        self::STATUS_SENT => 'An Mailserver übergeben',
        self::STATUS_DELIVERED => 'Zugestellt',
        self::STATUS_DELAYED => 'Verzögert, wird erneut versucht',
        self::STATUS_HELD => 'Zurückgehalten',
        self::STATUS_FAILED => 'Nicht zustellbar',
        self::STATUS_BOUNCED => 'Zurückgekommen',
    ];

    /** Mails, die kein Betrieb als Vorlage ändern kann. */
    private const SYSTEM_TEMPLATE_LABELS = [
        'guest_verify' => 'Link zur E-Mail-Bestätigung',
        'owner_new_reservation' => 'Hinweis an den Betrieb: neue Reservierung',
        'reservation_table_changed' => 'Tischänderung',
    ];

    protected $fillable = [
        'tenant_id', 'location_id', 'reservation_id', 'channel',
        'template_key', 'recipient', 'subject', 'status', 'error',
        'message_id', 'status_detail', 'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function hasFailed(): bool
    {
        return in_array($this->status, self::FAILED_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Farbe der Statusmarke: ok, wait, bad. */
    public function statusTone(): string
    {
        return match (true) {
            $this->status === self::STATUS_DELIVERED => 'ok',
            $this->hasFailed() => 'bad',
            default => 'wait',
        };
    }

    public function templateLabel(bool $salon = false): string
    {
        $key = (string) $this->template_key;

        return self::SYSTEM_TEMPLATE_LABELS[$key] ?? TemplateLabels::label($key, $salon);
    }

    /** Für den Gast bestimmt (alles außer den Hinweisen an den Betrieb). */
    public function isForGuest(): bool
    {
        return ! str_starts_with((string) $this->template_key, 'owner_');
    }
}

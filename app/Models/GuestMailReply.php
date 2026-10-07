<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine eingehende Antwort eines Gastes auf eine Buchungsmail, angenommen über
 * die Swayy-Antwortadresse. Gekoppelt an Buchung und Gast: Die Löschung der
 * Buchung entfernt sie, die Anonymisierung leert die personenbezogenen Felder.
 *
 * @property string $match_status matched|slug_only|unmatched
 * @property string $forward_status queued|sent|failed|skipped
 */
class GuestMailReply extends Model
{
    protected $fillable = [
        'tenant_id', 'location_id', 'reservation_id', 'guest_id',
        'postal_message_id', 'message_id_header', 'from_email', 'from_name',
        'to_recipient', 'subject', 'body_text', 'has_attachments', 'attachments_meta',
        'match_status', 'forwarded_to', 'forwarded_at', 'forward_status', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'has_attachments' => 'boolean',
            'attachments_meta' => 'array',
            'forwarded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}

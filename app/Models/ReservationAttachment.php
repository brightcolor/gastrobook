<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a reservation – menu agreement, seating sketch, signed
 * event contract. Files live on the private disk and are only ever served
 * through an authenticated route, never from public storage.
 */
class ReservationAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reservation_id', 'disk', 'path',
        'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Human-readable size for the UI. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}

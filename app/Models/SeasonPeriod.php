<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein wiederkehrendes buchbares Fenster eines Standorts (z. B. 1. April bis
 * 31. Oktober, jedes Jahr). Fällt kein Fenster auf einen Tag, nimmt die
 * Online-Buchung an diesem Tag keine Reservierung an. Ohne Fenster gilt keine
 * Einschränkung. Die Jahreslogik steckt im SeasonService.
 */
class SeasonPeriod extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'location_id', 'label',
        'start_month', 'start_day', 'end_month', 'end_day', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_month' => 'integer',
            'start_day' => 'integer',
            'end_month' => 'integer',
            'end_day' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return array<int, string> */
    public static function months(): array
    {
        return [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];
    }

    /** Lesbare Spanne, z. B. „1. April – 31. Oktober". */
    public function rangeLabel(): string
    {
        $months = self::months();
        $part = fn (int $m, int $d): string => $d.'. '.($months[$m] ?? '');

        return $part($this->start_month, $this->start_day).' – '.$part($this->end_month, $this->end_day);
    }
}

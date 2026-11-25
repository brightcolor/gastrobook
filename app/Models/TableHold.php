<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein gehaltener Tisch (oder eine Tischgruppe) für einen Gast, der gerade bucht. Ohne Mandanten-Scope: Die Zeilen
 * entstehen und gelten auf der öffentlichen Buchungsseite, wo es keinen angemeldeten Betrieb gibt; jede Abfrage nennt
 * den Standort selbst (App\Services\TableHolds).
 *
 * @property array<int, int> $table_ids
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $expires_at
 */
class TableHold extends Model
{
    protected $fillable = ['tenant_id', 'location_id', 'token', 'table_ids', 'starts_at', 'ends_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'table_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}

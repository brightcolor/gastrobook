<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;

class PlanLimitService
{
    /**
     * Grenzen, die beim Anlegen geprüft werden: Standorte, Zugänge, Tische und
     * Events. Plätze und Reservierungen je Monat zählen anders und gelten an
     * dieser Stelle nicht.
     */
    public const ENFORCED = ['max_locations', 'max_users', 'max_tables', 'max_events'];

    /** Bezeichnungen der Grenzen für Meldungen, Einzahl|Mehrzahl. */
    private const UNITS = [
        'max_locations' => ':count Standort|:count Standorte',
        'max_users' => ':count Zugang|:count Zugänge',
        'max_tables' => ':count Tisch|:count Tische',
        'max_events' => ':count Event|:count Events',
    ];

    /**
     * Whether the tenant may add one more of the given resource.
     */
    public function canAdd(Tenant $tenant, string $limitKey): bool
    {
        $limit = $tenant->limit($limitKey);
        if ($limit === null) {
            return true; // unlimited
        }

        return $this->currentUsage($tenant, $limitKey) < $limit;
    }

    /**
     * Legt etwas an und hält dabei die Grenze ein, auch wenn zwei Anfragen
     * gleichzeitig kommen. Die Prüfung "noch Platz?" und das Anlegen laufen in
     * einer Transaktion, die die Zeile des Betriebs sperrt: Die zweite Anfrage
     * wartet, zählt danach neu und sieht den Eintrag der ersten. Die Grenze
     * kommt dabei frisch aus der Datenbank. Unter PostgreSQL ist es eine Sperre
     * "for no key update": Sie schließt gleichzeitiges Anlegen aus, hält aber die
     * Fremdschlüsselprüfung anderer Zeilen des Betriebs (Reservierungen,
     * Protokoll, Gäste) nicht auf; "for update" täte das.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T|null null, wenn die Grenze erreicht ist und nichts angelegt wurde
     */
    public function createWithinLimit(Tenant $tenant, string $limitKey, Closure $create): mixed
    {
        return DB::transaction(function () use ($tenant, $limitKey, $create) {
            $locked = $this->lockTenant($tenant);
            if ($locked === null || ! $this->canAdd($locked, $limitKey)) {
                return null;
            }

            return $create();
        });
    }

    /**
     * Sperrt die Zeile des Betriebs bis zum Ende der laufenden Transaktion und
     * liefert sie frisch aus der Datenbank. Wer danach zählt und anlegt, kann
     * sich mit keiner anderen Anfrage überholen, die ebenfalls hier sperrt.
     * Nur innerhalb einer Transaktion aufrufen.
     */
    public function lockTenant(Tenant $tenant): ?Tenant
    {
        return Tenant::query()->whereKey($tenant->getKey())
            ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)
            ->first();
    }

    /**
     * Der Verbrauch aller Grenzen, die beim Anlegen gelten.
     *
     * @return array<string, int>
     */
    public function usage(Tenant $tenant): array
    {
        $usage = [];
        foreach (self::ENFORCED as $limitKey) {
            $usage[$limitKey] = $this->currentUsage($tenant, $limitKey);
        }

        return $usage;
    }

    /**
     * Grenzen, die seit $before überschritten wurden.
     *
     * Zählt nur, was gewachsen ist: Ein Betrieb, der nach einem Wechsel in
     * einen kleineren Tarif schon über einer Grenze liegt, bleibt handlungs-
     * fähig, solange er nichts hinzufügt.
     *
     * @param  array<string, int>  $before  Ergebnis von usage() vor der Änderung
     * @return array<string, array{limit: int, usage: int}>
     */
    public function exceededSince(Tenant $tenant, array $before): array
    {
        $exceeded = [];
        foreach ($before as $limitKey => $was) {
            $limit = $tenant->limit($limitKey);
            if ($limit === null) {
                continue;
            }
            $now = $this->currentUsage($tenant, $limitKey);
            if ($now > $limit && $now > $was) {
                $exceeded[$limitKey] = ['limit' => $limit, 'usage' => $now];
            }
        }

        return $exceeded;
    }

    /**
     * Überschrittene Grenzen als Satzteil, etwa „3 Tische, erlaubt sind 2“.
     *
     * @param  array<string, array{limit: int, usage: int}>  $exceeded  Ergebnis von exceededSince()
     */
    public function describe(array $exceeded): string
    {
        return collect($exceeded)
            ->map(fn (array $values, string $limitKey) => trans_choice(self::UNITS[$limitKey] ?? ':count '.$limitKey, $values['usage'], ['count' => $values['usage']])
                .', '.trans_choice('erlaubt ist :count|erlaubt sind :count', $values['limit'], ['count' => $values['limit']]))
            ->implode('; ');
    }

    public function currentUsage(Tenant $tenant, string $limitKey): int
    {
        return match ($limitKey) {
            'max_locations' => $tenant->locations()->count(),
            'max_users' => $tenant->memberships()->count(),
            'max_tables' => RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            'max_seats' => (int) RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->where('is_active', true)->sum('max_capacity'),
            'max_reservations_per_month' => Reservation::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'max_events' => Event::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            default => 0,
        };
    }
}

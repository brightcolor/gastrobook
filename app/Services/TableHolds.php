<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Location;
use App\Models\TableHold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Gehaltene Tische auf der öffentlichen Buchungsseite.
 *
 * Wählt ein Gast eine Uhrzeit, hält Swayy den passenden Tisch einige Minuten für ihn fest (Einstellung „Tisch beim
 * Buchen halten“ unter Kapazität & Dauer). Andere Gäste sehen den Tisch so lange als belegt. Das Team bucht davon
 * unberührt: Ein Halten ist eine Zusage auf der Buchungsseite und sperrt keine Buchung im Adminbereich.
 *
 * Läuft die Zeit ab, bucht der Gast trotzdem, solange der Tisch frei ist; die Buchung prüft beim Absenden ohnehin
 * erneut. Abgelaufene Zeilen zählen nicht mehr und verschwinden beim nächsten Halten am Standort.
 */
class TableHolds
{
    /** Länge der zufälligen Kennung, die das Buchungsformular mitschickt. */
    public const TOKEN_LENGTH = 40;

    /**
     * Tische, die andere Gäste gerade halten und deren Zeitraum das Fenster berührt.
     *
     * @return array<int, int>
     */
    public function heldTableIds(Location $location, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, ?string $exceptToken = null): array
    {
        return TableHold::query()
            ->where('location_id', $location->id)
            ->where('expires_at', '>', now())
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->when($exceptToken !== null && $exceptToken !== '', fn ($q) => $q->where('token', '!=', $exceptToken))
            ->pluck('table_ids')
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hält die Tische für den Zeitraum. Eine vorhandene Kennung desselben Standorts wird weiterverwendet: Wählt der Gast
     * eine andere Uhrzeit, ersetzt das neue Halten das alte.
     *
     * Läuft in der Transaktion und unter der Tagessperre des Aufrufers, damit zwei Gäste nicht denselben Tisch halten.
     *
     * @param  array<int, int>  $tableIds
     */
    public function hold(Location $location, ?string $token, array $tableIds, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $minutes): TableHold
    {
        TableHold::query()
            ->where('location_id', $location->id)
            ->where('expires_at', '<=', now())
            ->delete();

        $token = $this->validToken($token) ? $token : Str::random(self::TOKEN_LENGTH);
        // Eine fremde Kennung (anderer Standort) wird nicht übernommen, sondern ersetzt.
        $existing = TableHold::query()->where('token', $token)->first();
        if ($existing !== null && (int) $existing->location_id !== (int) $location->id) {
            $token = Str::random(self::TOKEN_LENGTH);
            $existing = null;
        }

        $values = [
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'token' => $token,
            'table_ids' => array_values(array_map('intval', $tableIds)),
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'expires_at' => now()->addMinutes($minutes),
        ];

        if ($existing !== null) {
            $existing->update($values);

            return $existing;
        }

        return TableHold::create($values);
    }

    /** Gibt das Halten frei, etwa nach der Buchung. */
    public function release(Location $location, ?string $token): void
    {
        if (! $this->validToken($token)) {
            return;
        }

        TableHold::query()->where('location_id', $location->id)->where('token', $token)->delete();
    }

    /** Entfernt abgelaufene Zeilen aller Standorte. */
    public function pruneExpired(): int
    {
        return TableHold::query()->where('expires_at', '<=', now())->delete();
    }

    public function validToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token) === 1;
    }
}

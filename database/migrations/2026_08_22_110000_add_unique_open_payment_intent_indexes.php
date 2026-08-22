<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Je Reservierung und je Eventbuchung hoechstens ein offener Zahlungsvorgang
 * derselben Art.
 *
 * Der Bezahllink legt den Vorgang mit firstOrCreate an - SELECT, dann INSERT,
 * und dazwischen passt ein zweiter Aufruf. Doppelklick oder zwei Tabs
 * erzeugten so zwei bezahlbare Sitzungen ueber denselben Betrag. Bezahlt der
 * Gast beide, steht im System eine Anzahlung, beim Anbieter liegen zwei - und
 * die Erstattung findet die zweite nicht, weil sie nur einen bezahlten
 * Vorgang sucht.
 *
 * Teilindex auf status='pending': Abgeschlossene, abgelaufene und
 * fehlgeschlagene Vorgaenge duerfen sich haeufen, sonst waere nach einem
 * Fehlversuch kein zweiter Anlauf moeglich.
 *
 * Idempotent, und bei vorhandenen Doppeln wird uebersprungen statt
 * abgebrochen: entrypoint.sh laesst migrate mit "set -e" laufen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createUnique('payment_intents_open_reservation_unique', 'reservation_id');
        $this->createUnique('payment_intents_open_event_booking_unique', 'event_booking_id');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_intents_open_reservation_unique');
        DB::statement('DROP INDEX IF EXISTS payment_intents_open_event_booking_unique');
    }

    private function createUnique(string $name, string $column): void
    {
        // Teilindizes kennt MySQL nicht.
        if (! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        if (Schema::hasIndex('payment_intents', $name)) {
            return;
        }

        $doppelt = DB::table('payment_intents')
            ->selectRaw($column.', type')
            ->whereNotNull($column)
            ->where('status', 'pending')
            ->groupBy($column, 'type')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($doppelt > 0) {
            Log::warning('Index '.$name.' nicht angelegt: '.$doppelt.' Buchungen haben mehr als einen offenen Zahlungsvorgang. Diese gehoeren geprueft - moeglicherweise wurde zweimal kassiert.');

            return;
        }

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON payment_intents (%s, type) WHERE %s IS NOT NULL AND status = 'pending'",
            $name,
            $column,
            $column
        ));
    }
};

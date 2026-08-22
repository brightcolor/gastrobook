<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Je Reservierung und je Eventbuchung hoechstens eine offene Erstattung.
 *
 * Die Anwendung serialisiert Erstattungsantraege ueber eine Sperre auf der
 * Reservierungs- bzw. Buchungszeile. Dieser Index ist der Rueckhalt darunter:
 * Er faengt jeden Weg ab, der die Sperre nicht nimmt - ein spaeterer
 * Codepfad, ein Konsolenbefehl, ein Import. Bei Geld ist ein zweiter Boden
 * die Muehe wert.
 *
 * Teilindex, weil abgelehnte und fehlgeschlagene Erstattungen mehrfach
 * vorkommen duerfen: Nach einem Fehlversuch soll ein neuer Anlauf moeglich
 * bleiben. PostgreSQL und SQLite kennen beide `CREATE UNIQUE INDEX ... WHERE`,
 * die Anweisung ist fuer beide dieselbe.
 *
 * Idempotent, und bei bereits vorhandenen Doppeln wird uebersprungen statt
 * abgebrochen: entrypoint.sh laesst migrate mit "set -e" laufen - eine
 * scheiternde Migration legt den Container in die Neustartschleife und damit
 * die ganze Anwendung still.
 */
return new class extends Migration
{
    private const OFFEN = "status NOT IN ('rejected', 'failed')";

    public function up(): void
    {
        $this->createUnique('refunds_open_reservation_unique', 'reservation_id');
        $this->createUnique('refunds_open_event_booking_unique', 'event_booking_id');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS refunds_open_reservation_unique');
        DB::statement('DROP INDEX IF EXISTS refunds_open_event_booking_unique');
    }

    private function createUnique(string $name, string $column): void
    {
        // Teilindizes kennt MySQL nicht. Dort bleibt die Sperre auf der
        // Reservierung der einzige Schutz - der traegt, nur ohne zweiten Boden.
        if (! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        if (Schema::hasIndex('refunds', $name)) {
            return;
        }

        $doppelt = DB::table('refunds')
            ->selectRaw($column)
            ->whereNotNull($column)
            ->whereRaw(self::OFFEN)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($doppelt > 0) {
            Log::warning('Index '.$name.' nicht angelegt: es gibt bereits '.$doppelt.' mehrfach belegte Werte in refunds.'.$column.'. Diese Doppel gehoeren geprueft - moeglicherweise ist Geld zweimal zurueckgegangen.');

            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON refunds (%s) WHERE %s IS NOT NULL AND %s',
            $name,
            $column,
            $column,
            self::OFFEN
        ));
    }
};

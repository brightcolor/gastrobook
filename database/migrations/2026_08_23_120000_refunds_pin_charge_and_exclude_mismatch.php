<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drei Dinge, die eine Erstattung selbst wissen muss.
 *
 * `charge_reference` - an WELCHER Belastung sie haengt. Bisher stand das nur
 * am Zahlungsvorgang, und der wird wiederverwendet: Zahlte ein Gast nach einer
 * abgewiesenen Zahlung erneut, zeigte die Referenz auf die NEUE Belastung. Die
 * wartende Erstattung holte sich das Geld dann von der richtigen Zahlung
 * zurueck, waehrend die falsche beim Anbieter liegen blieb.
 *
 * `first_attempt_at` - wann sie zum ersten Mal beim Anbieter war. Der Schutz
 * gegen die doppelte Auszahlung ist ein Wiederholungsschluessel, der dort 24
 * Stunden haelt. Gemessen wurde bisher an `updated_at`, und das setzt jeder
 * Versuch neu: Zwei Fehlversuche mit einem Tag Abstand liefen damit an dem
 * Schutz vorbei.
 *
 * Und die Teilindizes: Eine Erstattung wegen eines falschen Betrags gehoert
 * zur Buchung, ist aber keine ihrer Stornoerstattungen. Im Index belegte sie
 * den Platz fuer immer - die spaetere Stornoerstattung lief in die Verletzung
 * und wurde still verworfen.
 *
 * Idempotent: entrypoint.sh laesst migrate mit "set -e" laufen, eine
 * scheiternde Migration legt den Container in die Neustartschleife.
 */
return new class extends Migration
{
    private const OFFEN = "status NOT IN ('rejected', 'failed')";

    private const KEINE_ABWEICHUNG = "source <> 'amount_mismatch_refund'";

    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            if (! Schema::hasColumn('refunds', 'charge_reference')) {
                $table->string('charge_reference')->nullable()->after('provider_refund_id');
            }
            if (! Schema::hasColumn('refunds', 'first_attempt_at')) {
                $table->timestamp('first_attempt_at')->nullable()->after('processed_at');
            }
        });

        // Bestand nachtragen, solange die Referenz noch stimmt: Wer schon
        // ausgezahlt hat, soll bei einem spaeteren Blick nicht ins Leere sehen.
        $this->backfillChargeReference();

        $this->rebuildUnique('refunds_open_reservation_unique', 'reservation_id');
        $this->rebuildUnique('refunds_open_event_booking_unique', 'event_booking_id');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS refunds_open_reservation_unique');
        DB::statement('DROP INDEX IF EXISTS refunds_open_event_booking_unique');

        Schema::table('refunds', function (Blueprint $table) {
            if (Schema::hasColumn('refunds', 'charge_reference')) {
                $table->dropColumn('charge_reference');
            }
            if (Schema::hasColumn('refunds', 'first_attempt_at')) {
                $table->dropColumn('first_attempt_at');
            }
        });
    }

    private function backfillChargeReference(): void
    {
        DB::table('refunds')
            ->whereNull('charge_reference')
            ->whereNotNull('payment_intent_id')
            ->orderBy('id')
            ->chunkById(200, function ($zeilen) {
                foreach ($zeilen as $zeile) {
                    $metadata = DB::table('payment_intents')->where('id', $zeile->payment_intent_id)->value('metadata');
                    $referenz = is_string($metadata) ? (json_decode($metadata, true)['refund_ref'] ?? null) : null;

                    if (is_string($referenz) && $referenz !== '') {
                        DB::table('refunds')->where('id', $zeile->id)->update(['charge_reference' => $referenz]);
                    }
                }
            });
    }

    private function rebuildUnique(string $name, string $column): void
    {
        // Teilindizes kennt MySQL nicht. Dort bleibt die Sperre auf der
        // Reservierung der einzige Schutz.
        if (! in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$name);

        $bedingung = sprintf('%s IS NOT NULL AND %s AND %s', $column, self::OFFEN, self::KEINE_ABWEICHUNG);

        $doppelt = DB::table('refunds')
            ->selectRaw($column)
            ->whereNotNull($column)
            ->whereRaw(self::OFFEN)
            ->whereRaw(self::KEINE_ABWEICHUNG)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($doppelt > 0) {
            Log::warning('Index '.$name.' nicht angelegt: es gibt bereits '.$doppelt.' mehrfach belegte Werte in refunds.'.$column.'. Diese Doppel gehoeren geprueft - moeglicherweise ist Geld zweimal zurueckgegangen.');

            return;
        }

        DB::statement(sprintf('CREATE UNIQUE INDEX %s ON refunds (%s) WHERE %s', $name, $column, $bedingung));
    }
};

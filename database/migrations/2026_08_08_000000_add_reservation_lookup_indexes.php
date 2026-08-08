<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei Nachschlage-Indizes auf reservations.
 *
 * PostgreSQL legt fuer Fremdschluessel keinen Index an. Gelesen wird ueber diese
 * Spalten aber staendig: der Terminplan und die Salon-Verfuegbarkeit fragen je
 * Mitarbeiter nach einem Zeitfenster, das Gastprofil nach den letzten Besuchen.
 * Beides sind Gleichheit auf der Fremdschluesselspalte plus Bereich auf
 * start_at - deshalb zusammengesetzte Indizes statt zweier Einspalter.
 *
 * Idempotent: entrypoint.sh laesst migrate mit "set -e" laufen, eine zweite
 * Ausfuehrung wuerde den Container sonst in die Neustartschleife legen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasIndex('reservations', 'reservations_staff_start_idx')) {
                $table->index(['staff_member_id', 'start_at'], 'reservations_staff_start_idx');
            }
            if (! Schema::hasIndex('reservations', 'reservations_guest_start_idx')) {
                $table->index(['guest_id', 'start_at'], 'reservations_guest_start_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasIndex('reservations', 'reservations_staff_start_idx')) {
                $table->dropIndex('reservations_staff_start_idx');
            }
            if (Schema::hasIndex('reservations', 'reservations_guest_start_idx')) {
                $table->dropIndex('reservations_guest_start_idx');
            }
        });
    }
};

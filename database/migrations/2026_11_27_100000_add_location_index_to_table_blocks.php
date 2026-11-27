<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index auf table_blocks (location_id, ends_at).
 *
 * Die Liste der Ausnahmen liest die Tischsperren eines Standorts, die nach
 * einem Stichtag enden (ScheduleExceptions::bundles). Bisher gab es nur den
 * Index (restaurant_table_id, starts_at, ends_at); PostgreSQL legt für den
 * Fremdschlüssel location_id keinen an. blackout_periods hat einen Index mit
 * location_id vorn schon.
 *
 * Idempotent: entrypoint.sh lässt migrate mit "set -e" laufen, eine zweite
 * Ausführung würde den Container sonst in die Neustartschleife legen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('table_blocks', 'table_blocks_location_end_idx')) {
            return;
        }

        Schema::table('table_blocks', function (Blueprint $table) {
            $table->index(['location_id', 'ends_at'], 'table_blocks_location_end_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('table_blocks', 'table_blocks_location_end_idx')) {
            return;
        }

        Schema::table('table_blocks', function (Blueprint $table) {
            $table->dropIndex('table_blocks_location_end_idx');
        });
    }
};

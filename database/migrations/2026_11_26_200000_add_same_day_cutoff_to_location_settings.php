<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annahmeschluss für den laufenden Tag: Ab dieser Uhrzeit („HH:MM“) nimmt die
 * Online-Buchung für den laufenden Tag und dessen Nacht nichts mehr an. Leer
 * heißt: kein Annahmeschluss, wie bisher. Bestehende Zeilen bleiben leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('location_settings', 'same_day_cutoff')) {
            return;
        }

        Schema::table('location_settings', function (Blueprint $table) {
            $table->string('same_day_cutoff', 5)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('location_settings', 'same_day_cutoff')) {
            return;
        }

        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('same_day_cutoff');
        });
    }
};

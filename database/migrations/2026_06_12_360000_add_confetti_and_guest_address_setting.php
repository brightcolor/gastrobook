<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ohne after(): Die Bezugsspalte entstand in einer Migration mit
        // DEMSELBEN Zeitstempel, die erst danach laeuft - die Reihenfolge ergab
        // sich nur zufaellig aus dem Anfangsbuchstaben des Dateinamens. Auf
        // PostgreSQL und SQLite wird after() folgenlos verworfen, auf
        // MySQL/MariaDB bricht die Migration ab, und entrypoint.sh legt den
        // Container mit "set -e" in die Neustartschleife. Die Spaltenreihenfolge
        // ist nichts wert, was dieses Risiko rechtfertigt.
        Schema::table('location_settings', function (Blueprint $table) {
            $table->boolean('confetti_on_booking')->default(true);
            $table->string('guest_address')->default('Sie');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn(['confetti_on_booking', 'guest_address']);
        });
    }
};

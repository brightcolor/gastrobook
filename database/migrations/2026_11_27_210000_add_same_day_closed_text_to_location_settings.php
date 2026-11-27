<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eigener Hinweis je Standort für Gäste, wenn der Annahmeschluss für heute
 * erreicht ist. Leer heißt: der Standardtext. Die Spalte ist neu.
 *
 * Idempotent: entrypoint.sh lässt migrate mit "set -e" laufen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_settings', 'same_day_closed_text')) {
            Schema::table('location_settings', fn (Blueprint $table) => $table->text('same_day_closed_text')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('location_settings', 'same_day_closed_text')) {
            Schema::table('location_settings', fn (Blueprint $table) => $table->dropColumn('same_day_closed_text'));
        }
    }
};

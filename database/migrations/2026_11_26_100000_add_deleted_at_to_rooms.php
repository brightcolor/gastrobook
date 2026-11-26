<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Räume werden weich gelöscht. Ein endgültig gelöschter Raum nahm über den
 * Fremdschlüssel restaurant_tables.room_id (cascadeOnDelete) auch seine weich
 * gelöschten Tische mit, und vergangene Reservierungen verloren damit ihre
 * Tischzuordnung. Bestehende Zeilen bleiben unverändert (deleted_at = null).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rooms', 'deleted_at')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rooms', 'deleted_at')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

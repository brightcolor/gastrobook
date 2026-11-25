<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tisch beim Buchen halten: Hat ein Gast eine Uhrzeit gewählt, hält Swayy den Tisch einige Minuten für ihn fest, während
 * er seine Angaben einträgt. Vorgabe aus; ohne eigene Minutenzahl gilt booking.hold_default_minutes aus config/swayy.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('location_settings', 'table_hold_enabled')) {
            Schema::table('location_settings', function (Blueprint $table) {
                $table->boolean('table_hold_enabled')->default(false);
            });
        }
        if (! Schema::hasColumn('location_settings', 'table_hold_minutes')) {
            Schema::table('location_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('table_hold_minutes')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['table_hold_minutes', 'table_hold_enabled'] as $column) {
            if (Schema::hasColumn('location_settings', $column)) {
                Schema::table('location_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

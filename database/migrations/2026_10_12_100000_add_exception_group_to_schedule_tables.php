<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gruppe einer Ausnahme (Einstellungen › Platz & Zeiten › Ausnahmen): Alle
 * Zeilen, die eine Ausnahme anlegt, tragen dieselbe UUID. Zeilen ohne Gruppe
 * stammen aus der Zeit davor und erscheinen einzeln. Läuft mehrfach ohne
 * Fehler, weil docker/entrypoint.sh migrate bei jedem Start aufruft.
 */
return new class extends Migration
{
    private const TABLES = ['special_opening_hours', 'blackout_periods', 'table_blocks'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'exception_group')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->uuid('exception_group')->nullable();
                });
            }
            if (! Schema::hasIndex($name, $name.'_exception_group_index')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->index('exception_group');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasIndex($name, $name.'_exception_group_index')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex($name.'_exception_group_index'));
            }
            if (Schema::hasColumn($name, 'exception_group')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('exception_group'));
            }
        }
    }
};

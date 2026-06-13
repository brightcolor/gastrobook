<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            // Opt-in: zeigt Gästen einen read-only Tischplan mit Verfügbarkeit
            // auf der öffentlichen Buchungsseite (Restaurant-Modus).
            $table->boolean('public_floorplan_enabled')->default(false)->after('gap_optimization_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('public_floorplan_enabled');
        });
    }
};

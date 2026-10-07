<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('location_settings', 'large_group_email')) {
                // Adresse für Gruppen oberhalb der größten online buchbaren Runde.
                // Leer blendet den "Mehr"-Knopf auf der Buchungsseite aus.
                $table->string('large_group_email')->nullable()->after('max_party_online');
            }
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('large_group_email');
        });
    }
};

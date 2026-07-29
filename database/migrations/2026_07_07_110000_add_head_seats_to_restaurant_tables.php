<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_tables', 'head_seats_enabled')) {
                // Stühle an den Stirnseiten (Kopfenden) eines eckigen Tisches.
                $table->boolean('head_seats_enabled')->default(true)->after('high_chair_possible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn('head_seats_enabled');
        });
    }
};

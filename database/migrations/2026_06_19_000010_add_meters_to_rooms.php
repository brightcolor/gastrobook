<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('plan_width_m', 6, 2)->nullable()->after('plan_height');
            $table->decimal('plan_height_m', 6, 2)->nullable()->after('plan_width_m');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['plan_width_m', 'plan_height_m']);
        });
    }
};

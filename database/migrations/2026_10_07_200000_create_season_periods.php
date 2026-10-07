<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60)->nullable();
            // Wiederkehrend jedes Jahr, daher Monat/Tag ohne Jahr.
            $table->unsignedTinyInteger('start_month'); // 1-12
            $table->unsignedTinyInteger('start_day');   // 1-31
            $table->unsignedTinyInteger('end_month');
            $table->unsignedTinyInteger('end_day');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_periods');
    }
};

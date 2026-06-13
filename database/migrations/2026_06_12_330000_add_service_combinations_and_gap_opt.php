<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A salon appointment can combine several services (e.g. cut + colour).
        // The reservation's start/end already span the combined duration; this
        // pivot records which services make up the appointment, in order.
        Schema::create('reservation_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('duration_minutes'); // snapshot at booking time
            $table->unsignedInteger('price_minor')->default(0); // snapshot at booking time
            $table->unique(['reservation_id', 'service_id']);
        });

        Schema::table('location_settings', function (Blueprint $table) {
            // Gap optimizer: when assigning "any" staff, prefer the member whose
            // schedule the appointment fits most snugly (reduces dead gaps).
            $table->boolean('gap_optimization_enabled')->default(false)->after('sms_reminder_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('gap_optimization_enabled');
        });
        Schema::dropIfExists('reservation_service');
    }
};

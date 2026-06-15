<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->boolean('confetti_on_booking')->default(true)->after('require_email_confirmation');
            $table->string('guest_address')->default('Sie')->after('confetti_on_booking');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn(['confetti_on_booking', 'guest_address']);
        });
    }
};

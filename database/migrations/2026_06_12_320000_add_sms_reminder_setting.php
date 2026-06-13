<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            // Opt-in: even with SMS credentials configured, reminders go out
            // only when this is explicitly enabled (avoids accidental spend).
            $table->boolean('sms_reminder_enabled')->default(false)->after('reminder_hours_before');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('sms_reminder_enabled');
        });
    }
};

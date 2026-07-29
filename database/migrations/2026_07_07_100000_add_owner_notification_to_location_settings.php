<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('location_settings', 'owner_notification_enabled')) {
                $table->boolean('owner_notification_enabled')->default(false)->after('confetti_on_booking');
            }
            if (! Schema::hasColumn('location_settings', 'owner_notification_email')) {
                // Falls leer, wird die Standort-Kontakt-E-Mail verwendet.
                $table->string('owner_notification_email')->nullable()->after('owner_notification_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn(['owner_notification_enabled', 'owner_notification_email']);
        });
    }
};

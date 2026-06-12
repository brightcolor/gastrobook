<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_bookings', function (Blueprint $table) {
            $table->string('manage_token', 64)->default('')->after('code');
            $table->text('note')->nullable()->after('guest_phone');
            $table->timestamp('cancelled_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_bookings', function (Blueprint $table) {
            $table->dropColumn(['manage_token', 'note', 'cancelled_at']);
        });
    }
};

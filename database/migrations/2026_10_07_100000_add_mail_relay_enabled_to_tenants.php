<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'mail_relay_enabled')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('mail_relay_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'mail_relay_enabled')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('mail_relay_enabled');
            });
        }
    }
};

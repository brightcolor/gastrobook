<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('saas_role')->nullable()->after('password'); // super_admin, support_admin, billing_admin, readonly_admin
            $table->string('locale', 10)->default('de')->after('saas_role');
            $table->unsignedBigInteger('current_tenant_id')->nullable()->after('locale');
            $table->unsignedBigInteger('current_location_id')->nullable()->after('current_tenant_id');
            $table->boolean('is_active')->default(true)->after('current_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['saas_role', 'locale', 'current_tenant_id', 'current_location_id', 'is_active']);
        });
    }
};

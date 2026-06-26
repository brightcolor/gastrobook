<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'onboarding_completed_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('type');
            });

            // Mark all existing tenants that already have opening hours as done so
            // existing operators are not redirected into the setup wizard.
            DB::statement('
                UPDATE tenants
                SET    onboarding_completed_at = created_at
                WHERE  EXISTS (
                    SELECT 1 FROM locations
                    JOIN   opening_hours ON opening_hours.location_id = locations.id
                    WHERE  locations.tenant_id = tenants.id
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};

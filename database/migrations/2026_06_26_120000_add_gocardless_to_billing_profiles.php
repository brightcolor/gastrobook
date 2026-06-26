<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_profiles', 'gocardless_customer_id')) {
                $table->string('gocardless_customer_id')->nullable()->after('stripe_customer_id');
            }
            if (! Schema::hasColumn('billing_profiles', 'gocardless_mandate_id')) {
                $table->string('gocardless_mandate_id')->nullable()->after('gocardless_customer_id');
            }
            if (! Schema::hasColumn('billing_profiles', 'gocardless_subscription_id')) {
                $table->string('gocardless_subscription_id')->nullable()->after('gocardless_mandate_id');
            }
            if (! Schema::hasColumn('billing_profiles', 'gocardless_status')) {
                // none | pending | active | cancelled | failed
                $table->string('gocardless_status')->default('none')->after('gocardless_subscription_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'gocardless_customer_id',
                'gocardless_mandate_id',
                'gocardless_subscription_id',
                'gocardless_status',
            ]);
        });
    }
};

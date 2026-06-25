<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Contact / billing address
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('company_name')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20);
            $table->string('city');
            $table->string('country', 2)->default('DE');
            $table->string('vat_id', 50)->nullable();
            $table->string('phone', 40)->nullable();

            // Desired plan
            $table->string('plan_key', 30);

            // Optional message
            $table->text('notes')->nullable();

            // Email confirmation flow
            $table->string('token', 80)->unique();
            $table->timestamp('confirmed_at')->nullable();     // set when user clicks link
            $table->timestamp('owner_notified_at')->nullable(); // set when owner is forwarded

            $table->timestamps();
        });

        // Track whether the 5-day warning was already sent
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_warning_sent_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_requests');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_warning_sent_at');
        });
    }
};

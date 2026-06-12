<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // trial, starter, professional, multi_location, enterprise
            $table->string('name');
            $table->unsignedInteger('price_monthly_minor')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->json('limits'); // max_locations, max_users, max_tables, max_seats, max_reservations_per_month, ...
            $table->json('features'); // api_enabled, webhooks_enabled, deposits_enabled, waitlist_enabled, ...
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active, suspended, cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('default_locale', 10)->default('de');
            $table->string('default_currency', 3)->default('EUR');
            // Branding
            $table->string('brand_logo_path')->nullable();
            $table->string('brand_primary_color', 9)->nullable();
            $table->string('brand_accent_color', 9)->nullable();
            $table->string('mail_from_name')->nullable();
            $table->string('mail_reply_to')->nullable();
            $table->string('imprint_url')->nullable();
            $table->string('privacy_url')->nullable();
            $table->string('terms_url')->nullable();
            // GDPR
            $table->unsignedInteger('guest_retention_months')->default(36);
            $table->json('settings')->nullable();
            $table->json('feature_overrides')->nullable(); // per-tenant feature flags set by SaaS admin
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // tenant_owner, tenant_admin, operations_manager, location_manager, host, staff, marketing_manager, readonly
            $table->boolean('all_locations')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->boolean('all_locations')->default(true);
            $table->json('location_ids')->nullable();
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('DE');
            $table->string('vat_id')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('payment_status')->default('none'); // none, active, past_due, cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};

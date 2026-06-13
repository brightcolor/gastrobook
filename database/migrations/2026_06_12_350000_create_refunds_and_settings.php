<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider'); // stripe, paypal
            $table->string('provider_refund_id')->nullable();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3)->default('EUR');
            // pending (awaiting approval) → approved → processing → completed | failed; rejected
            $table->string('status')->default('pending');
            $table->string('source')->default('system'); // guest_cancel, staff, system
            $table->string('reason', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_for')->nullable(); // do not process before
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('location_settings', function (Blueprint $table) {
            // off | manual (staff approves) | auto
            $table->string('refund_mode')->default('off')->after('public_floorplan_enabled');
            // 0–100 % of the paid deposit that is refunded on a timely cancellation
            $table->unsignedTinyInteger('refund_percent')->default(100)->after('refund_mode');
            // immediate (process on approval) | scheduled (batch via cron)
            $table->string('refund_processing')->default('immediate')->after('refund_percent');
        });
    }

    public function down(): void
    {
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn(['refund_mode', 'refund_percent', 'refund_processing']);
        });
        Schema::dropIfExists('refunds');
    }
};

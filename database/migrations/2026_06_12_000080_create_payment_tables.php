<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('deposit'); // deposit, card_guarantee, prepayment
            $table->unsignedSmallInteger('min_party_size')->nullable();
            $table->json('weekdays')->nullable(); // [4,5] = Fri/Sat (0 = Monday)
            $table->time('from_time')->nullable();
            $table->time('until_time')->nullable();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_per_person_minor')->default(0);
            $table->unsignedInteger('flat_amount_minor')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('payment_deadline_minutes')->default(60); // after booking
            $table->boolean('cancel_unpaid_automatically')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider'); // stripe, mollie, manual
            $table->string('provider_intent_id')->nullable(); // NEVER store card data, only provider refs
            $table->string('type'); // deposit, card_guarantee, prepayment, no_show_fee
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('pending'); // pending, authorized, paid, failed, refunded, partially_refunded, cancelled, expired
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('deposit_rules');
    }
};

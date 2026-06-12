<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable();
            $table->string('code', 12)->unique(); // public reservation reference
            $table->string('manage_token', 64); // secret for guest cancel/modify links
            $table->unsignedSmallInteger('party_size');
            $table->date('reservation_date'); // local date at location
            $table->dateTime('start_at'); // UTC
            $table->dateTime('end_at');   // UTC
            $table->string('timezone');
            $table->string('status')->default('confirmed');
            $table->string('source')->default('manual'); // online, manual, phone, walk_in, api, phone_assistant, event
            $table->string('occasion')->nullable();
            // Guest snapshots — survive guest edits/anonymization
            $table->string('guest_name_snapshot');
            $table->string('guest_email_snapshot')->nullable();
            $table->string('guest_phone_snapshot')->nullable();
            $table->text('guest_note')->nullable();     // note from guest
            $table->text('allergy_note')->nullable();
            $table->text('internal_note')->nullable();  // staff-only
            // Payment / no-show protection
            $table->string('payment_status')->default('not_required');
            $table->unsignedInteger('payment_amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('payment_due_at')->nullable();
            // Lifecycle timestamps
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('feedback_requested_at')->nullable();
            $table->unsignedTinyInteger('no_show_risk')->default(0); // 0-100
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'location_id', 'reservation_date']);
            $table->index(['location_id', 'start_at', 'end_at']);
            $table->index(['location_id', 'status']);
        });

        Schema::create('reservation_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->unique(['reservation_id', 'restaurant_table_id'], 'res_table_unique');
            $table->index('restaurant_table_id');
        });

        Schema::create('reservation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system or guest
            $table->string('actor')->default('user'); // user, guest, system
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('reservation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_attachments');
        Schema::dropIfExists('reservation_notes');
        Schema::dropIfExists('reservation_status_histories');
        Schema::dropIfExists('reservation_tables');
        Schema::dropIfExists('reservations');
    }
};

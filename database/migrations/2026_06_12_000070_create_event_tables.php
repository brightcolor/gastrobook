<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');   // UTC
            $table->unsignedSmallInteger('capacity');
            $table->unsignedInteger('price_minor')->nullable();
            $table->unsignedInteger('deposit_minor')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->dateTime('booking_deadline_at')->nullable();
            $table->dateTime('cancellation_deadline_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('waitlist_enabled')->default(false);
            $table->json('field_rules')->nullable();
            $table->string('status')->default('published'); // draft, published, cancelled, completed
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['location_id', 'slug']);
        });

        Schema::create('event_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 12)->unique();
            $table->unsignedSmallInteger('ticket_count');
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('status')->default('confirmed'); // requested, confirmed, cancelled, checked_in
            $table->string('payment_status')->default('not_required');
            $table->unsignedInteger('amount_minor')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_bookings');
        Schema::dropIfExists('events');
    }
};

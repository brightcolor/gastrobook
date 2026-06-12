<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('restaurant'); // restaurant, cafe, bar, hotel, event_location
            $table->string('timezone')->default('Europe/Berlin');
            $table->string('currency', 3)->default('EUR');
            $table->string('locale', 10)->default('de');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('DE');
            $table->boolean('is_active')->default(true);
            $table->boolean('online_booking_enabled')->default(true);
            $table->text('public_intro')->nullable(); // shown on booking page
            $table->string('brand_logo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['location_id', 'user_id']);
        });

        Schema::create('location_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->unique()->constrained()->cascadeOnDelete();
            // Slot generation
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30);
            $table->unsignedSmallInteger('default_duration_minutes')->default(120);
            $table->json('duration_rules')->nullable(); // [{min_party, max_party, duration}, ...] or by daypart
            $table->unsignedSmallInteger('buffer_minutes')->default(0); // turn-time buffer between seatings
            // Booking rules
            $table->unsignedSmallInteger('min_lead_minutes')->default(60);
            $table->unsignedSmallInteger('max_advance_days')->default(90);
            $table->unsignedSmallInteger('min_party_online')->default(1);
            $table->unsignedSmallInteger('max_party_online')->default(8);
            $table->boolean('auto_confirm')->default(true);
            $table->boolean('request_only')->default(false); // all bookings become requests
            $table->string('capacity_mode')->default('table'); // table, person, hybrid
            $table->unsignedSmallInteger('max_covers_per_slot')->nullable(); // for person/hybrid mode
            $table->boolean('waitlist_enabled')->default(true);
            $table->boolean('walkins_enabled')->default(true);
            // Cancellation / modification
            $table->unsignedSmallInteger('cancellation_deadline_minutes')->default(120);
            $table->unsignedSmallInteger('modification_deadline_minutes')->default(120);
            // Required fields config: each value: 'hidden' | 'optional' | 'required'
            $table->json('field_rules')->nullable();
            // Reminder / feedback
            $table->boolean('reminder_enabled')->default(true);
            $table->unsignedSmallInteger('reminder_hours_before')->default(24);
            $table->boolean('feedback_enabled')->default(true);
            $table->unsignedSmallInteger('feedback_hours_after')->default(18);
            $table->string('feedback_external_url')->nullable(); // e.g. Google review link
            $table->unsignedTinyInteger('feedback_redirect_min_score')->default(4);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Monday ... 6 = Sunday (ISO-8601 dayOfWeekIso - 1)
            $table->time('opens_at');
            $table->time('closes_at');
            $table->string('service_name')->nullable(); // e.g. Mittag, Abend
            $table->timestamps();
            $table->index(['location_id', 'weekday']);
        });

        Schema::create('special_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('label')->nullable(); // Feiertag, Betriebsferien, ...
            $table->text('staff_note')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'date']);
        });

        Schema::create('blackout_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable(); // null = whole location
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');   // UTC
            $table->unsignedSmallInteger('reduce_covers_to')->nullable(); // null = fully blocked
            $table->string('reason')->nullable();
            $table->text('staff_note')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blackout_periods');
        Schema::dropIfExists('special_opening_hours');
        Schema::dropIfExists('opening_hours');
        Schema::dropIfExists('location_settings');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
    }
};

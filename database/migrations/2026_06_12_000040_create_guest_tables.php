<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable(); // digits only, for dedupe/search
            $table->string('address_line1')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->date('birthday')->nullable();
            $table->string('locale', 10)->nullable();
            $table->foreignId('preferred_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('preferred_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('preferred_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->text('preferences')->nullable();
            $table->text('allergies')->nullable();
            $table->text('accessibility_notes')->nullable();
            $table->boolean('is_vip')->default(false);
            $table->unsignedInteger('visit_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->unsignedInteger('cancellation_count')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->decimal('avg_party_size', 4, 1)->nullable();
            $table->string('source')->default('manual'); // manual, online_booking, import, api, walk_in
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->boolean('anonymized')->default(false);
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone_normalized']);
            $table->index(['tenant_id', 'last_name']);
        });

        Schema::create('guest_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_sensitive')->default(false); // requires extra permission
            $table->timestamps();
        });

        Schema::create('guest_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // privacy, newsletter, marketing, feedback
            $table->boolean('granted');
            $table->string('channel')->nullable(); // booking_widget, staff, import, api
            $table->string('ip_hash')->nullable(); // truncated/hashed, GDPR-minimized
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['guest_id', 'type']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#6b7280');
            $table->string('scope')->default('guest'); // guest, reservation, both
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name', 'scope']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable'); // Guest, Reservation
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });

        Schema::create('guest_merge_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kept_guest_id')->constrained('guests')->cascadeOnDelete();
            $table->unsignedBigInteger('merged_guest_id'); // deleted afterwards, keep raw id
            $table->json('merged_snapshot');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_merge_logs');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('guest_consents');
        Schema::dropIfExists('guest_notes');
        Schema::dropIfExists('guests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manage_token', 64);
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->date('desired_date');
            $table->dateTime('desired_start_at')->nullable(); // UTC, preferred time
            $table->unsignedSmallInteger('flex_minutes')->default(60); // acceptable window +/-
            $table->string('status')->default('waiting'); // waiting, offered, accepted, declined, expired, seated, cancelled
            $table->string('source')->default('online'); // online, walk_in, staff
            $table->unsignedInteger('priority')->default(100);
            $table->text('note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete(); // filled when converted
            $table->timestamps();
            $table->index(['location_id', 'desired_date', 'status']);
        });

        Schema::create('waitlist_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->dateTime('offered_start_at'); // UTC
            $table->dateTime('offered_end_at');
            $table->json('table_ids')->nullable();
            $table->timestamp('offer_expires_at');
            $table->string('status')->default('open'); // open, accepted, declined, expired
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_offers');
        Schema::dropIfExists('waitlist_entries');
    }
};

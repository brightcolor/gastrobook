<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic guest campaigns: birthday greeting, rebooking reminder, win-back.
 *
 * The data they need (birthday, last_visit_at, visit_count, marketing_consent)
 * was there from the start – only nothing ever acted on it.
 *
 * `marketing_sends` is what keeps the whole thing honest: one row per guest,
 * campaign and occasion. Without it a daily job would mail the same person
 * again every single day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->constrained()->cascadeOnDelete();
                $table->string('type'); // birthday, rebooking, winback
                $table->string('name');
                $table->boolean('is_active')->default(false);
                $table->unsignedSmallInteger('offset_days')->default(0);
                $table->unsignedSmallInteger('min_visits')->default(1);
                $table->string('subject');
                $table->text('body');
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('marketing_sends')) {
            Schema::create('marketing_sends', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
                $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
                // Occasion: birthday year, date of the visit, win-back year.
                $table->string('reference', 32);
                $table->timestamp('sent_at');
                $table->timestamps();
                $table->unique(['marketing_campaign_id', 'guest_id', 'reference'], 'marketing_send_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_sends');
        Schema::dropIfExists('marketing_campaigns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete(); // null = tenant default
            $table->string('key'); // reservation_confirmed, reservation_requested, reservation_cancelled, reminder, feedback_request, waitlist_offer, ...
            $table->string('locale', 10)->default('de');
            $table->string('subject');
            $table->text('body'); // with {placeholders}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'location_id', 'key', 'locale'], 'tmpl_unique');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel'); // mail, sms, whatsapp, webhook, internal
            $table->string('template_key')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status')->default('queued'); // queued, sent, failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 1-5
            $table->text('comment')->nullable();
            $table->string('locale', 10)->nullable();
            $table->boolean('redirected_external')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
    }
};

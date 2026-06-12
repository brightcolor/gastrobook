<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('secret', 64);
            $table->json('events'); // subscribed event names, ['*'] = all
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status')->default('pending'); // pending, success, failed
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider'); // stripe, mollie, mailchimp, brevo, twilio, whatsapp, google_reserve, pos, pms, ...
            $table->string('status')->default('disconnected'); // disconnected, connected, error
            $table->text('credentials_encrypted')->nullable(); // encrypted JSON
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'location_id', 'provider']);
        });

        Schema::create('conversation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel'); // phone_assistant, chat
            $table->string('external_ref')->nullable();
            $table->json('transcript')->nullable();
            $table->string('outcome')->nullable(); // booked, cancelled, handed_over, info_only
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_logs');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};

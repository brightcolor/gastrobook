<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_mail_replies')) {
            return;
        }

        Schema::create('guest_mail_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('postal_message_id')->unique();
            $table->string('message_id_header')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('to_recipient');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->json('attachments_meta')->nullable();
            $table->string('match_status'); // matched, slug_only, unmatched
            $table->string('forwarded_to')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->string('forward_status')->default('queued'); // queued, sent, failed, skipped
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id']);
            $table->index(['tenant_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_mail_replies');
    }
};

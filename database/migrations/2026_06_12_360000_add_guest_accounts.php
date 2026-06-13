<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::table('location_settings', function (Blueprint $table) {
            // Require guests to confirm their email (once) before a booking is final
            $table->boolean('require_email_confirmation')->default(false)->after('refund_processing');
        });

        // Passwordless magic links: portal login + email verification
        Schema::create('guest_auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('purpose'); // login | verify
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['guest_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_auth_tokens');
        Schema::table('location_settings', function (Blueprint $table) {
            $table->dropColumn('require_email_confirmation');
        });
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};

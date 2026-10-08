<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Vertrag aus dem Kundenpanel am Betrieb (Bereitstellungs-Schnittstelle,
 * Version 1), dazu die Aufrufkennungen und die Anmeldelinks.
 *
 * Ein Betrieb ohne contract_ref laeuft wie bisher. Mit Vertrag kommen Zustand,
 * Tarif und die Grenzen max_locations und max_tables aus dem Panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contract_ref', 60)->nullable()->unique();
            $table->string('contract_url', 500)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('state_reason', 20)->nullable();
            $table->string('state_before', 20)->nullable();
            $table->string('tariff_code', 40)->nullable();
            $table->string('tariff_label', 200)->nullable();
            $table->string('tariff_kind', 20)->nullable();
            $table->boolean('free_place')->default(false);
            $table->timestamp('tariff_ends_at')->nullable();
            $table->json('contract_limits')->nullable();
        });

        Schema::create('provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->string('request_hash', 64);
            // Vor der Arbeit geschrieben und mit ihr festgeschrieben; ein
            // gespeicherter Eintrag traegt immer den endgueltigen Status.
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->json('response')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('provisioning_login_links', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_login_links');
        Schema::dropIfExists('provisioning_requests');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropUnique(['contract_ref']);
            $table->dropColumn([
                'contract_ref', 'contract_url', 'approved_at', 'state_reason', 'state_before',
                'tariff_code', 'tariff_label', 'tariff_kind', 'free_place', 'tariff_ends_at', 'contract_limits',
            ]);
        });
    }
};

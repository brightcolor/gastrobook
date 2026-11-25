<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gehaltene Tische: Ein Gast hat eine Uhrzeit gewählt und trägt gerade seine Angaben ein. Bis expires_at gelten die
 * Tische für andere Gäste als belegt. Eine Zeile enthält keine Angaben zur Person, nur Tische, Zeitraum und eine
 * zufällige Kennung, die das Buchungsformular mitschickt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('table_holds')) {
            return;
        }

        Schema::create('table_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->json('table_ids');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['location_id', 'expires_at'], 'table_holds_location_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_holds');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which deposit rule made a booking payable.
 *
 * Without it the expiry scheduler cannot tell whether the business wanted
 * unpaid bookings cancelled automatically — the per-rule flag existed but was
 * unreachable at that point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'deposit_rule_id')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('deposit_rule_id')->nullable()->after('payment_due_at')
                ->constrained('deposit_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'deposit_rule_id')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_rule_id');
        });
    }
};

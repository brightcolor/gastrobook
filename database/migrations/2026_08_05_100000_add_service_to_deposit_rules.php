<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit rules could be tied to party size, time, room and event – but not to
 * a service. In a salon that is the natural handle: a balayage is worth an
 * advance payment, a fringe trim is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('deposit_rules', 'service_id')) {
            return;
        }

        Schema::table('deposit_rules', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('event_id')
                ->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('deposit_rules', 'service_id')) {
            return;
        }

        Schema::table('deposit_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};

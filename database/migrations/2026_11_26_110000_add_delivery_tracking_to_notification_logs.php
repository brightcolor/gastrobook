<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zustellstatus im Versandprotokoll: die Message-ID, über die Postal seine
 * Meldungen zuordnet, die letzte Antwort des Mailservers und der Zeitpunkt
 * dieser Meldung. Idempotent, weil der Container `migrate` bei jedem Start
 * ausführt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_logs', 'message_id')) {
                $table->string('message_id')->nullable();
            }
            if (! Schema::hasColumn('notification_logs', 'status_detail')) {
                $table->text('status_detail')->nullable();
            }
            if (! Schema::hasColumn('notification_logs', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }
        });

        if (! Schema::hasIndex('notification_logs', 'notification_logs_message_id_index')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index('message_id');
            });
        }

        // Die Liste im Admin und die Buchungsseite lesen die Mails einer
        // Reservierung, neueste zuerst.
        if (! Schema::hasIndex('notification_logs', 'notification_logs_reservation_id_created_at_index')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index(['reservation_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notification_logs', 'notification_logs_reservation_id_created_at_index')) {
            Schema::table('notification_logs', fn (Blueprint $table) => $table->dropIndex(['reservation_id', 'created_at']));
        }
        if (Schema::hasIndex('notification_logs', 'notification_logs_message_id_index')) {
            Schema::table('notification_logs', fn (Blueprint $table) => $table->dropIndex(['message_id']));
        }
        foreach (['message_id', 'status_detail', 'status_changed_at'] as $column) {
            if (Schema::hasColumn('notification_logs', $column)) {
                Schema::table('notification_logs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};

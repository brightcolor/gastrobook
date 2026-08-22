<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei Nachschlage-Indizes auf guest_auth_tokens.
 *
 * PostgreSQL legt fuer Fremdschluessel keinen Index an. Seit die
 * E-Mail-Bestaetigung bei jeder Buchung greift, waechst die Tabelle um eine
 * Zeile pro Onlinebuchung, und ExpireUnconfirmedReservations fragt sie
 * stuendlich zweimal ab - beide Male ueber purpose + reservation_id. Der
 * zweite Index bedient das Aufraeumen abgelaufener Token.
 *
 * Idempotent: entrypoint.sh laesst migrate mit "set -e" laufen, eine zweite
 * Ausfuehrung wuerde den Container sonst in die Neustartschleife legen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_auth_tokens', function (Blueprint $table) {
            if (! Schema::hasIndex('guest_auth_tokens', 'guest_auth_tokens_purpose_reservation_idx')) {
                $table->index(['purpose', 'reservation_id'], 'guest_auth_tokens_purpose_reservation_idx');
            }
            if (! Schema::hasIndex('guest_auth_tokens', 'guest_auth_tokens_cleanup_idx')) {
                $table->index(['expires_at', 'used_at'], 'guest_auth_tokens_cleanup_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('guest_auth_tokens', function (Blueprint $table) {
            if (Schema::hasIndex('guest_auth_tokens', 'guest_auth_tokens_purpose_reservation_idx')) {
                $table->dropIndex('guest_auth_tokens_purpose_reservation_idx');
            }
            if (Schema::hasIndex('guest_auth_tokens', 'guest_auth_tokens_cleanup_idx')) {
                $table->dropIndex('guest_auth_tokens_cleanup_idx');
            }
        });
    }
};

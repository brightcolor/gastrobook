<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anmeldung zum Newsletter je Betrieb: eigener Häkchentext, Name des Knopfes
 * der Bestätigungsmail, Double-Opt-in und die Felder, die an das
 * Newsletter-System gehen. guest_consents.consent_text hält fest, welchen Text
 * der Gast beim Zustimmen gesehen hat. Alle Spalten sind neu; bestehende Werte
 * bleiben unverändert.
 *
 * Idempotent: entrypoint.sh lässt migrate mit "set -e" laufen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'newsletter_consent_text' => fn (Blueprint $table) => $table->text('newsletter_consent_text')->nullable(),
            'newsletter_confirm_button' => fn (Blueprint $table) => $table->string('newsletter_confirm_button')->nullable(),
            'newsletter_double_opt_in' => fn (Blueprint $table) => $table->boolean('newsletter_double_opt_in')->default(true),
            'newsletter_fields' => fn (Blueprint $table) => $table->json('newsletter_fields')->nullable(),
        ];
        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('tenants', $column)) {
                Schema::table('tenants', fn (Blueprint $table) => $add($table));
            }
        }

        if (! Schema::hasColumn('guest_consents', 'consent_text')) {
            Schema::table('guest_consents', fn (Blueprint $table) => $table->text('consent_text')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['newsletter_consent_text', 'newsletter_confirm_button', 'newsletter_double_opt_in', 'newsletter_fields'] as $column) {
            if (Schema::hasColumn('tenants', $column)) {
                Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('guest_consents', 'consent_text')) {
            Schema::table('guest_consents', fn (Blueprint $table) => $table->dropColumn('consent_text'));
        }
    }
};

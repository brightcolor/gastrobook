<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zählt eine Buchung ohne Newsletter-Haken als Widerruf? Vorgabe nein: Eine
 * erteilte Einwilligung bleibt bestehen, der Gast widerruft über den
 * Abmeldelink. Der Betrieb stellt es unter Einstellungen › Betrieb › Allgemein um.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'newsletter_unticked_revokes')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('newsletter_unticked_revokes')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'newsletter_unticked_revokes')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('newsletter_unticked_revokes');
            });
        }
    }
};

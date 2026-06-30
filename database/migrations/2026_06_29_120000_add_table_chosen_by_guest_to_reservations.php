<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservations', 'table_chosen_by_guest')) {
            Schema::table('reservations', function (Blueprint $table) {
                // True when the guest actively picked the table on the public
                // floor plan (vs. automatic assignment). Lets staff see it as a
                // guest wish and trigger a counter-proposal when reassigning.
                $table->boolean('table_chosen_by_guest')->default(false)->after('source');
            });
        }
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('table_chosen_by_guest');
        });
    }
};

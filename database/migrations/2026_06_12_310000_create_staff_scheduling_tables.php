<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regular weekly working hours per staff member. When a member has at
        // least one row for a weekday, only those windows count as available
        // (intersected with the location's opening hours). No rows for a member
        // at all = falls back to location opening hours (backwards compatible).
        Schema::create('staff_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Monday ... 6 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->index(['staff_member_id', 'weekday']);
        });

        // One-off absences (vacation, sick leave, training). Stored in UTC.
        Schema::create('staff_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at'); // UTC
            $table->timestamp('ends_at');   // UTC
            $table->string('reason', 120)->nullable();
            $table->timestamps();
            $table->index(['staff_member_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_absences');
        Schema::dropIfExists('staff_working_hours');
    }
};

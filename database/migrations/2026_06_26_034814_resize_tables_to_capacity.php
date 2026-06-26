<?php

use App\Models\RestaurantTable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Recompute every table's floor-plan footprint from its capacity using the
     * realistic, uncapped sizing (1 unit = 1 cm). Existing tables were sized
     * with the old capped formula, so large tables were too small and their
     * chairs overlapped. Deterministic and safe to re-run.
     */
    public function up(): void
    {
        RestaurantTable::query()
            ->withoutGlobalScopes()
            ->select('id', 'shape', 'max_capacity')
            ->chunkById(200, function ($tables) {
                foreach ($tables as $table) {
                    [$w, $h] = RestaurantTable::sizeForCapacity($table->shape ?? 'rect', (int) $table->max_capacity);
                    $table->newQuery()->withoutGlobalScopes()
                        ->whereKey($table->id)
                        ->update(['width' => $w, 'height' => $h]);
                }
            });
    }

    public function down(): void
    {
        // Sizing is derived data — nothing to revert.
    }
};

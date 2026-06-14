<?php

use App\Models\RestaurantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-size existing tables so their footprint matches the seat count
     * (older tables were all created at a fixed default size).
     */
    public function up(): void
    {
        DB::table('restaurant_tables')
            ->select('id', 'shape', 'max_capacity')
            ->orderBy('id')
            ->chunk(200, function ($tables) {
                foreach ($tables as $t) {
                    [$w, $h] = RestaurantTable::sizeForCapacity($t->shape ?: 'rect', (int) $t->max_capacity);
                    DB::table('restaurant_tables')->where('id', $t->id)->update(['width' => $w, 'height' => $h]);
                }
            });
    }

    public function down(): void
    {
        // No-op: previous per-table sizes are not recoverable.
    }
};

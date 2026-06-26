<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RestaurantTable;
use PHPUnit\Framework\TestCase;

class TableSizeTest extends TestCase
{
    public function test_rectangular_tables_match_real_world_dimensions(): void
    {
        // [capacity => [width, height]] in cm (1 plan unit = 1 cm)
        $this->assertSame([70, 70], RestaurantTable::sizeForCapacity('rect', 2));
        $this->assertSame([120, 80], RestaurantTable::sizeForCapacity('rect', 4));
        $this->assertSame([180, 80], RestaurantTable::sizeForCapacity('rect', 6));
    }

    public function test_round_tables_match_real_world_dimensions(): void
    {
        // Diameter never below 90 cm; grows with circumference need.
        [$d2] = RestaurantTable::sizeForCapacity('round', 2);
        $this->assertSame(90, $d2);

        [$d4] = RestaurantTable::sizeForCapacity('round', 4);
        $this->assertSame(90, $d4);

        [$d8] = RestaurantTable::sizeForCapacity('round', 8);
        $this->assertGreaterThan(140, $d8);
    }

    public function test_size_grows_without_cap_so_seats_never_overlap(): void
    {
        // Each added pair of covers must enlarge the table — no plateau/cap.
        $prev = 0;
        foreach ([4, 8, 12, 20, 50, 100] as $n) {
            [$len] = RestaurantTable::sizeForCapacity('rect', $n);
            $this->assertGreaterThan($prev, $len, "Length must keep growing at capacity {$n}");
            $prev = $len;
        }

        // A 100-cover banquet table must keep ≥ 55 cm of edge per cover on its
        // busiest long side, otherwise chairs would overlap. With 2 covers on
        // the heads, each long side carries ceil(98/2)=49 covers.
        [$len100] = RestaurantTable::sizeForCapacity('rect', 100);
        $coversPerLongSide = (int) ceil((100 - 2) / 2);
        $this->assertGreaterThanOrEqual(55, $len100 / $coversPerLongSide);
    }

    public function test_round_grows_without_cap(): void
    {
        [$d8] = RestaurantTable::sizeForCapacity('round', 8);
        [$d40] = RestaurantTable::sizeForCapacity('round', 40);
        $this->assertGreaterThan($d8 * 3, $d40);
    }
}

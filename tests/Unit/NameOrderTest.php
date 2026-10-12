<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NameOrder;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NameOrderTest extends TestCase
{
    /** @return Collection<int, object> */
    private function named(string ...$names): Collection
    {
        return collect($names)->map(fn (string $name) => (object) ['name' => $name, 'sort_order' => 0]);
    }

    public function test_umlauts_stand_at_their_base_letter(): void
    {
        $this->assertSame(
            ['Abend', 'Älteste', 'Ärmel', 'Bart', 'Öl', 'Zoe'],
            NameOrder::inOrder($this->named('Zoe', 'Bart', 'Ärmel', 'Älteste', 'Öl', 'Abend'))->pluck('name')->all(),
        );
    }

    public function test_numbers_count_as_numbers_and_case_does_not_decide_the_place(): void
    {
        $this->assertSame(
            ['tisch 2', 'Tisch 10', 'Tisch 21'],
            NameOrder::inOrder($this->named('Tisch 21', 'Tisch 10', 'tisch 2'))->pluck('name')->all(),
        );
        $this->assertSame(['anna', 'Berta', 'Carl'], NameOrder::inOrder($this->named('Carl', 'anna', 'Berta'))->pluck('name')->all());
    }

    public function test_the_set_order_comes_before_the_name(): void
    {
        $items = collect([
            (object) ['name' => 'Anna', 'sort_order' => 5],
            (object) ['name' => 'Zoe', 'sort_order' => null],
            (object) ['name' => 'Bert', 'sort_order' => 0],
        ]);

        $this->assertSame(['Bert', 'Zoe', 'Anna'], NameOrder::inOrder($items)->pluck('name')->all());
    }

    public function test_compare_reads_like_the_spaceship_operator(): void
    {
        $this->assertSame(-1, NameOrder::compare('Abend', 'Älteste'));
        $this->assertSame(1, NameOrder::compare('Bart', 'Älteste'));
        $this->assertSame(0, NameOrder::compare('Bart', 'Bart'));
    }

    public function test_the_rules_follow_the_language_of_the_application(): void
    {
        // Im Schwedischen steht „ä“ hinter dem „z“.
        config(['app.locale' => 'sv']);
        $this->assertSame(1, NameOrder::compare('Älteste', 'Zoe'));

        config(['app.locale' => 'de']);
        $this->assertSame(-1, NameOrder::compare('Älteste', 'Zoe'));
    }
}

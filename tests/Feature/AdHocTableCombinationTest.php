<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TableAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Large groups must not be turned away just because nobody defined a matching
 * table combination: free joinable tables in the same room are combined on the fly.
 */
class AdHocTableCombinationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function assign(array $setup, int $partySize, array $options = []): ?array
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0)->utc();

        return app(TableAssignmentService::class)->findTables(
            $setup['location'], $start, $start->addHours(2), $partySize, $options
        );
    }

    public function test_combines_tables_for_a_group_larger_than_any_single_table(): void
    {
        // tables: 1–2, 2–4, 4–8 → 14 seats total, biggest single table seats 8
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $result = $this->assign($setup, 12);

        $this->assertNotNull($result, '12 Personen sollten auf mehrere Tische verteilt werden');
        $this->assertGreaterThanOrEqual(2, count($result['table_ids']));
        $this->assertStringContainsString('ad_hoc_combination', $result['reason']);
    }

    public function test_single_table_still_wins_when_it_fits(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $result = $this->assign($setup, 4);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['table_ids'], 'Ein passender Einzeltisch hat Vorrang');
    }

    public function test_returns_null_when_even_all_tables_are_too_small(): void
    {
        $setup = $this->createTenantSetup(); // 14 seats in total
        $this->clearTenantContext();

        $this->assertNull($this->assign($setup, 30));
    }

    public function test_non_joinable_tables_are_not_combined(): void
    {
        $setup = $this->createTenantSetup();
        foreach ($setup['tables'] as $table) {
            $table->update(['joinable' => false]);
        }
        $this->clearTenantContext();

        $this->assertNull($this->assign($setup, 12), 'Ohne kombinierbare Tische keine Ad-hoc-Kombination');
    }

    public function test_online_only_uses_online_bookable_tables(): void
    {
        $setup = $this->createTenantSetup();
        // Take the big table offline → online groups can no longer reach 12 seats
        $setup['tables'][2]->update(['online_bookable' => false]);
        $this->clearTenantContext();

        $this->assertNull($this->assign($setup, 12, ['online' => true]));
        // Staff (offline) may still combine everything
        $this->assertNotNull($this->assign($setup, 12, ['online' => false]));
    }
}

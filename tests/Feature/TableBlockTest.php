<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TableBlock;
use App\Services\ReservationLifecycleService;
use App\Services\TableAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Blocking a single table (defect, repairs, needed for something else).
 * The read side existed from day one – assignment, manual choice, floor plan
 * and live board all honoured `table_blocks` – but nothing could ever create
 * one. These tests cover the write path end to end.
 */
class TableBlockTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_admin_can_block_a_table_and_the_window_is_stored_in_utc(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->toDateString();
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/table-blocks', [
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $day.'T18:00',
            'ends_at' => $day.'T23:00',
            'reason' => 'Tisch defekt',
        ])->assertRedirect();

        $block = TableBlock::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($setup['tables'][0]->id, $block->restaurant_table_id);
        $this->assertSame('Tisch defekt', $block->reason);
        $this->assertSame($admin->id, $block->created_by);
        $this->assertSame(
            Carbon::parse($day.'T18:00', $tz)->utc()->toDateTimeString(),
            $block->starts_at->toDateTimeString()
        );
    }

    public function test_a_blocked_table_is_no_longer_handed_out(): void
    {
        // Exactly one table, so the block is the only thing that can fail.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(19, 0);

        TableBlock::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $start->subHour()->utc(),
            'ends_at' => $start->addHours(3)->utc(),
            'reason' => 'Reparatur',
        ]);
        $this->clearTenantContext();

        $free = app(TableAssignmentService::class)
            ->freeTables($setup['location'], $start->utc(), $start->addHours(2)->utc());
        $this->assertCount(0, $free);

        $this->expectException(ValidationException::class);
        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $start,
            'source' => 'manual',
            'guest_name' => 'Gast',
        ]);
    }

    public function test_a_blocked_table_cannot_be_picked_by_hand_either(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(19, 0);

        TableBlock::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $start->subHour()->utc(),
            'ends_at' => $start->addHours(3)->utc(),
        ]);
        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $start,
            'source' => 'manual',
            'guest_name' => 'Gast',
            'table_ids' => [$setup['tables'][0]->id],
        ]);
    }

    public function test_blocking_over_an_existing_booking_is_refused(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(19, 0);
        $day = $start->toDateString();
        $this->clearTenantContext();

        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $start,
            'source' => 'manual',
            'guest_name' => 'Gast',
            'table_ids' => [$setup['tables'][0]->id],
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/table-blocks', [
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $day.'T18:00',
            'ends_at' => $day.'T23:00',
        ])->assertSessionHasErrors('restaurant_table_id');

        $this->assertSame(0, TableBlock::withoutGlobalScopes()->count());
    }

    public function test_a_block_can_be_lifted_again(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(19, 0);

        $block = TableBlock::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $start->subHour()->utc(),
            'ends_at' => $start->addHours(3)->utc(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/settings/table-blocks/'.$block->id)->assertRedirect();
        $this->assertSame(0, TableBlock::withoutGlobalScopes()->count());

        // …and the table is bookable again.
        $this->clearTenantContext();
        $free = app(TableAssignmentService::class)
            ->freeTables($setup['location'], $start->utc(), $start->addHours(2)->utc());
        $this->assertCount(1, $free);
    }

    public function test_service_staff_may_not_block_tables(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->toDateString();
        $this->clearTenantContext();

        $this->actingAs($staff)->post('/admin/settings/table-blocks', [
            'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $day.'T18:00',
            'ends_at' => $day.'T23:00',
        ])->assertForbidden();
    }

    public function test_a_table_of_another_location_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->toDateString();
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/table-blocks', [
            'restaurant_table_id' => $other['tables'][0]->id,
            'starts_at' => $day.'T18:00',
            'ends_at' => $day.'T23:00',
        ])->assertSessionHasErrors('restaurant_table_id');

        $this->assertSame(0, TableBlock::withoutGlobalScopes()->count());
    }
}

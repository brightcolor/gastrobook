<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FloorPlanEditorTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00')); // 14:00 Europe/Berlin
    }

    public function test_editor_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/floorplan')
            ->assertOk()
            ->assertSee('Tischplan')
            ->assertSee('Bearbeiten');
    }

    public function test_table_can_be_created_from_the_editor(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->postJson('/admin/floorplan/tables', [
            'room_id' => $setup['room']->id,
            'name' => '99',
            'min_capacity' => 2,
            'max_capacity' => 6,
            'shape' => 'round',
            'pos_x' => 120,
            'pos_y' => 80,
        ])->assertOk();

        $response->assertJsonPath('table.name', '99');
        $response->assertJsonPath('table.seats', 6);
        $response->assertJsonPath('table.shape', 'round');

        $this->assertDatabaseHas('restaurant_tables', [
            'location_id' => $setup['location']->id,
            'name' => '99',
            'max_capacity' => 6,
            'pos_x' => 120,
        ]);

        // Capacity-based footprint: a round table is a circle (square box) and
        // larger than a 2-seater.
        $table = RestaurantTable::where('name', '99')->withoutGlobalScopes()->first();
        $this->assertSame($table->width, $table->height);
        [$smallW] = RestaurantTable::sizeForCapacity('round', 2);
        $this->assertGreaterThan($smallW, $table->width);
    }

    public function test_rectangular_size_grows_with_seats(): void
    {
        [$w4] = RestaurantTable::sizeForCapacity('rect', 4);
        [$w10] = RestaurantTable::sizeForCapacity('rect', 10);
        $this->assertGreaterThan($w4, $w10);
    }

    public function test_state_reports_seats_and_occupancy(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $start = CarbonImmutable::now('Europe/Berlin')->subMinutes(15);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 3,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Seated,
            'source' => 'online',
            'guest_name_snapshot' => 'Platz Gast',
        ]);
        $reservation->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $now = CarbonImmutable::now('Europe/Berlin')->format('H:i');
        $response = $this->actingAs($admin)
            ->getJson('/admin/floorplan/state?date='.$start->toDateString().'&time='.$now)
            ->assertOk();

        $table = collect($response->json('tables'))->firstWhere('id', $setup['tables'][0]->id);
        $this->assertSame(4, $table['seats']);
        $this->assertSame(3, $table['occupied']);
    }

    public function test_room_background_can_be_uploaded_served_and_cleared(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $room = $setup['room'];
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/floorplan/rooms/'.$room->id.'/background', [
            'image' => UploadedFile::fake()->image('plan.jpg', 800, 600),
        ])->assertOk()->assertJsonStructure(['url']);

        $room->refresh();
        $this->assertNotNull($room->background_path);
        Storage::disk('public')->assertExists($room->background_path);

        $this->actingAs($admin)->get('/admin/floorplan/rooms/'.$room->id.'/background')->assertOk();

        $path = $room->background_path;
        $this->actingAs($admin)->deleteJson('/admin/floorplan/rooms/'.$room->id.'/background')->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertNull($room->fresh()->background_path);
    }

    /**
     * The page ships its own <style> block that forces .hidden to win over the
     * .fp-* display rules. That override must stay scoped to this page's own
     * elements: the admin sidebar shows itself with "hidden md:flex", and a
     * blanket ".hidden { display: none !important }" beat it – the whole
     * navigation disappeared and the plan looked like a fullscreen view.
     */
    public function test_hidden_override_stays_scoped_so_the_sidebar_survives(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $html = $this->actingAs($admin)->get('/admin/floorplan')->assertOk()->getContent();

        // No unscoped override: a rule whose selector is a bare ".hidden".
        // (Matching on the declaration alone would also hit the scoped rule,
        // which legitimately ends in the same characters.)
        $this->assertDoesNotMatchRegularExpression('/(^|[\s,{;])\.hidden\s*\{/m', $html);
        // But the scoped one is still there, so the edit toggle keeps working.
        $this->assertStringContainsString('[class*="fp-"].hidden', $html);
        // And the navigation is on the page, including its own entry.
        $this->assertStringContainsString('hidden w-60', $html);
        $this->assertStringContainsString('Tischplan', $html);
    }
}

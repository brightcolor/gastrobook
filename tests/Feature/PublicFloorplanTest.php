<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PublicFloorplanTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_floorplan_endpoint_404_when_disabled(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/floorplan?date='.$date.'&time=19:00&party_size=2')
            ->assertNotFound();
    }

    public function test_floorplan_lists_rooms_and_marks_occupied_table(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        $startUtc = $date->setTime(19, 0)->utc();

        // Occupy the 2–4 table (index 1)
        $busyTable = $setup['tables'][1];
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $date->toDateString(),
            'start_at' => $startUtc,
            'end_at' => $startUtc->addMinutes(120),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Besetzt',
        ]);
        $reservation->tables()->attach($busyTable->id);

        $response = $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/floorplan?date='.$date->toDateString().'&time=19:00&party_size=2')
            ->assertOk()
            ->assertJsonStructure(['rooms' => [['id', 'name', 'is_outdoor', 'tables' => [['id', 'name', 'status', 'selectable', 'pos_x', 'pos_y']]]]]);

        $tables = collect($response->json('rooms'))->flatMap(fn ($r) => $r['tables']);
        $this->assertSame('occupied', $tables->firstWhere('id', $busyTable->id)['status']);
        // Free 1–2 table fits a party of 2 → available
        $this->assertSame('available', $tables->firstWhere('id', $setup['tables'][0]->id)['status'] ?? 'x');
        // Free 4–8 table is too large for a party of 2 → unsuitable
        $this->assertSame('unsuitable', $tables->firstWhere('id', $setup['tables'][2]->id)['status'] ?? 'x');
    }

    public function test_guest_can_pick_a_specific_table(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();
        $table = $setup['tables'][1]; // 2–4 seats

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Tischwahl',
            'email' => 'wahl@example.test',
            'phone' => '+49 170 1234567',
            'table_id' => $table->id,
            'privacy_accepted' => '1',
        ])->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->where('guest_name_snapshot', 'Tischwahl')->first();
        $this->assertNotNull($reservation);
        $this->assertTrue($reservation->tables->contains('id', $table->id));
    }

    public function test_booking_page_renders_floorplan_section_outside_collapsing_step(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $this->clearTenantContext();

        $html = $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()
            ->getContent();

        // Section must be present …
        $this->assertStringContainsString('id="floorplanSection"', $html);

        // … and must NOT sit inside step 2's collapsing .sp-body (which gets
        // display:none once a time slot is picked). Regression guard: the
        // floorplan section must appear after step 2's panel closes.
        $sp2Pos = strpos($html, 'id="sp2"');
        $sp3Pos = strpos($html, 'id="sp3"');
        $fpPos = strpos($html, 'id="floorplanSection"');
        $this->assertNotFalse($fpPos);
        $this->assertTrue($fpPos > $sp2Pos && $fpPos < $sp3Pos,
            'Floorplan must live between step 2 and step 3, not inside the collapsing step body.');
    }

    public function test_too_small_table_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();
        $smallTable = $setup['tables'][0]; // 1–2 seats

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 4,
            'name' => 'ZuGross',
            'email' => 'zugross@example.test',
            'phone' => '+49 170 1234567',
            'table_id' => $smallTable->id,
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('table_id');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationTimelineTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_timeline_view_renders_with_reservation_bar(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][1];

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Timeline Gast', 'code' => 'R-TL1', 'manage_token' => str_repeat('t', 48),
        ]);
        $r->tables()->attach($table->id);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->get('/admin/reservations?view=timeline&range=custom&from='.$start->toDateString().'&to='.$start->toDateString())
            ->assertOk()
            ->assertSee('Timeline Gast')
            ->assertSee($table->name)
            ->assertSee('jetzt'); // timeline legend marker
    }

    public function test_list_view_is_default(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('Reservierungsbuch')
            ->assertDontSee('Ohne feste Tischzuweisung');
    }
}

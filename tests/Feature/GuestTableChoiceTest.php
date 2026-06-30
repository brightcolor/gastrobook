<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestTableChoiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_guest_choice_is_flagged_and_shown_on_confirmation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();
        $table = $setup['tables'][1]; // 2–4 seats

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date, 'time' => '19:00', 'party_size' => 2,
            'name' => 'Wunschgast', 'email' => 'wunsch@example.test', 'phone' => '+49 170 1',
            'table_id' => $table->id, 'privacy_accepted' => '1',
        ])->assertRedirect();

        $r = Reservation::withoutGlobalScopes()->where('guest_name_snapshot', 'Wunschgast')->first();
        $this->assertNotNull($r);
        $this->assertTrue($r->table_chosen_by_guest);
        $this->assertTrue($r->tables->contains('id', $table->id));

        // Guest confirmation page shows the chosen table as "Wunschtisch".
        $this->get(route('booking.confirmation', ['code' => $r->code, 'token' => $r->manage_token]))
            ->assertOk()
            ->assertSee('Wunschtisch')
            ->assertSee($table->name);
    }

    public function test_reassigning_a_guest_table_sends_counter_proposal_and_clears_flag(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Wunschgast', 'guest_email_snapshot' => 'wunsch@example.test',
            'table_chosen_by_guest' => true,
        ]);
        $r->tables()->attach($setup['tables'][0]->id);

        app(ReservationLifecycleService::class)->reassignTables($r, [$setup['tables'][1]->id]);

        Mail::assertQueued(TemplatedMail::class);
        $this->assertFalse($r->fresh()->table_chosen_by_guest);
        $this->assertTrue($r->fresh()->tables->contains('id', $setup['tables'][1]->id));
    }

    public function test_no_counter_proposal_when_table_was_not_guest_chosen(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Autogast', 'guest_email_snapshot' => 'auto@example.test',
            'table_chosen_by_guest' => false,
        ]);
        $r->tables()->attach($setup['tables'][0]->id);

        app(ReservationLifecycleService::class)->reassignTables($r, [$setup['tables'][1]->id]);

        Mail::assertNothingQueued();
    }
}

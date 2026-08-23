<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Wartelisten-Angebote.
 *
 * Ein Angebot haelt den Platz nicht - es sagt ihn nur zu. Der Betrieb darf
 * denselben Abend also mehreren Wartenden anbieten, solange Platz da ist; und
 * "Gast jetzt platzieren" darf daran nie scheitern, denn dort steht der Gast
 * bereits am Tresen.
 */
class WaitlistOfferTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function entry(array $setup, string $name, int $party = 2): WaitlistEntry
    {
        return WaitlistEntry::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_name' => $name,
            'guest_email' => mb_strtolower($name).'@example.test',
            'party_size' => $party,
            'desired_date' => CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString(),
            'status' => 'waiting',
        ]);
    }

    /**
     * Vierzig Tische, zwei Wartende: Beide duerfen ein Angebot bekommen. Eine
     * Sperre auf "irgendein offenes Angebot im selben Zeitfenster" haette den
     * Betrieb auf ein einziges Angebot pro Abend eingedampft.
     */
    public function test_two_guests_can_hold_an_offer_for_the_same_evening(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([
            ['min' => 1, 'max' => 4], ['min' => 1, 'max' => 4], ['min' => 1, 'max' => 4],
        ]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        foreach ([$anna, $bert] as $eintrag) {
            $this->actingAs($admin)
                ->post('/admin/waitlist/'.$eintrag->id.'/offer', ['time' => '19:00'])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, WaitlistOffer::withoutGlobalScopes()->where('status', 'open')->count());
    }

    /**
     * Ist wirklich nichts mehr frei, wird das Angebot abgelehnt - eine Zusage
     * "Ein Tisch ist frei geworden" ohne Tisch waere schlimmer als keine.
     */
    public function test_an_offer_is_refused_when_nothing_is_left(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        // Der einzige Tisch ist damit zugesagt.
        $this->actingAs($admin)
            ->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');
    }

    /**
     * Und ein abgelehnter Anlauf darf dem Gast nicht sein gueltiges Angebot
     * nehmen: Der Link in seiner Mail waere danach tot, ohne dass es jemand
     * merkt.
     */
    public function test_a_refused_offer_leaves_the_existing_one_intact(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        // Bert bekommt den einzigen Tisch.
        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        // Anna belegt ihn nicht mehr - aber Berts erneuter Anlauf auch nicht.
        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');

        $this->assertSame(1, WaitlistOffer::withoutGlobalScopes()
            ->where('waitlist_entry_id', $bert->id)
            ->where('status', 'open')
            ->count(), 'Berts gueltiges Angebot ist verschwunden.');
        $this->assertSame('offered', $bert->fresh()->status);
    }

    /**
     * "Gast jetzt platzieren" steht am Tresen. Ein offenes Angebot bei jemand
     * anderem darf das nie aufhalten.
     */
    public function test_seating_a_guest_now_works_despite_another_open_offer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/seat')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $bert->fresh()->status);
        $this->assertDatabaseHas('reservations', [
            'location_id' => $setup['location']->id,
            'guest_name_snapshot' => 'Bert',
        ]);
    }

    /**
     * Ein abgelaufenes, noch nicht aufgeraeumtes Angebot darf "jetzt
     * platzieren" nicht blockieren - acceptOffer wiese es als "nicht mehr
     * gueltig" ab.
     */
    public function test_seating_a_guest_now_ignores_an_expired_offer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $bert = $this->entry($setup, 'Bert');
        WaitlistOffer::create([
            'tenant_id' => $setup['tenant']->id,
            'waitlist_entry_id' => $bert->id,
            'offered_start_at' => now()->subHours(3),
            'offered_end_at' => now()->subHour(),
            'offer_expires_at' => now()->subHours(2),
            'status' => 'open',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/seat')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $bert->fresh()->status);
    }
}

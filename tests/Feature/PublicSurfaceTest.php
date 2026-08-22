<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die oeffentliche Seite: was der Gast sieht, was er absenden kann und was
 * dabei still danebengeht.
 */
class PublicSurfaceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function buchungsUrl(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $abweichend
     * @return array<string, mixed>
     */
    private function formular(array $setup, array $abweichend = []): array
    {
        return array_merge([
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(2)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Frau Kessler',
            'email' => 'kessler@example.test',
            'phone' => '+49 30 000000',
            'privacy_accepted' => '1',
        ], $abweichend);
    }

    // ── Newsletter ────────────────────────────────────────────────────────

    /**
     * array_filter warf den nicht gesetzten Haken aus dem Array - der Widerruf
     * wurde nie verbucht. Der Gast blieb im Verteiler, und in der
     * Einwilligungshistorie fehlte der Eintrag, den Art. 7 Abs. 3 verlangt.
     */
    public function test_unticking_the_newsletter_records_the_withdrawal(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->post($this->buchungsUrl($setup), $this->formular($setup, ['newsletter' => '1']))
            ->assertRedirect();

        $guest = Guest::withoutGlobalScopes()->where('email', 'kessler@example.test')->sole();
        $this->assertTrue((bool) $guest->marketing_consent);

        // Zweite Buchung, diesmal ohne Haken.
        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
        ]))->assertRedirect();

        $this->assertFalse((bool) $guest->fresh()->marketing_consent);
        $this->assertSame(1, GuestConsent::withoutGlobalScopes()
            ->where('guest_id', $guest->id)
            ->where('type', 'newsletter')
            ->where('granted', false)
            ->count());
    }

    // ── Stornogrund ───────────────────────────────────────────────────────

    /**
     * Der Grund landet in einer varchar(255)-Spalte. Ungeprueft brach die
     * Stornierung auf PostgreSQL mit einem Serverfehler ab - der Gast sah eine
     * Fehlerseite, die Buchung blieb stehen.
     */
    public function test_an_overlong_cancellation_reason_is_rejected_cleanly(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $this->post(route('booking.cancel', ['code' => $reservation->code, 'token' => $reservation->manage_token]), [
            'reason' => str_repeat('a', 300),
        ])->assertSessionHasErrors('reason');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    // ── Warteliste ────────────────────────────────────────────────────────

    public function test_a_waitlist_entry_for_a_past_date_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->post($this->buchungsUrl($setup).'/waitlist', [
            'date' => CarbonImmutable::now()->subDay()->toDateString(),
            'party_size' => 2,
            'name' => 'Frau Kessler',
            'email' => 'kessler@example.test',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    // ── Einbindungs-Skripte ───────────────────────────────────────────────

    /**
     * sole() wirft bei mehreren Standorten eine Ausnahme, die als 500 endet.
     * Betroffen war genau das Skript, das ein Betrieb auf seiner eigenen
     * Website einbindet.
     */
    public function test_the_embed_script_answers_404_when_the_tenant_has_several_locations(): void
    {
        $setup = $this->createTenantSetup();
        $zweiter = Location::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'is_active' => true,
            'online_booking_enabled' => true,
        ]);
        Room::factory()->create(['location_id' => $zweiter->id, 'tenant_id' => $setup['tenant']->id]);
        $this->clearTenantContext();

        $this->get('/embed/'.$setup['tenant']->slug.'.js')->assertNotFound();
    }

    public function test_the_embed_script_still_works_for_a_single_location(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/embed/'.$setup['tenant']->slug.'.js')->assertOk();
    }

    // ── Verwaltungsseite ──────────────────────────────────────────────────

    /**
     * ?paid=1 ist frei setzbar. Die Seite meldete damit eine Anzahlung, die nie
     * eingegangen war, und blendete zugleich den Bezahlknopf aus - die Buchung
     * verfiel danach an der Zahlungsfrist.
     */
    public function test_the_paid_parameter_does_not_claim_a_payment(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::PaymentPending,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
            'guest_email_snapshot' => 'kessler@example.test',
            'payment_status' => 'required', 'payment_amount_minor' => 4000, 'currency' => 'EUR',
        ]);
        $this->clearTenantContext();

        $this->get(route('booking.manage', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]).'?paid=1')
            ->assertOk()
            ->assertDontSee('Anzahlung erhalten');
    }
}

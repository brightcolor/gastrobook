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

/**
 * Beim Ändern einer Reservierung gelten für die Personenzahl dieselben
 * Einstellungen wie beim Buchen: dieselben Knöpfe und derselbe Weg für große
 * Gruppen. Die bereits gebuchte Zahl bleibt wählbar.
 */
class ReschedulePartySizeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $settings
     * @return array{setup: array<string, mixed>, reservation: Reservation}
     */
    private function reservation(array $settings = [], int $partySize = 2): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00', 'Europe/Berlin'));
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_party_online' => 2, 'max_party_online' => 30, 'large_group_email' => 'gruppen@example.test', ...$settings,
        ]);
        $start = CarbonImmutable::parse('2026-12-04 19:00', 'Europe/Berlin');
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => $partySize, 'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(), 'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed, 'source' => 'online', 'guest_name_snapshot' => 'Gruppe Test',
        ]);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        return ['setup' => $setup, 'reservation' => $reservation];
    }

    private function changePage(Reservation $reservation): string
    {
        return $this->get('/reservation/'.$reservation->code.'/reschedule/'.$reservation->manage_token)->assertOk()->getContent();
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookingPage(array $setup): string
    {
        return $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)->assertOk()->getContent();
    }

    /**
     * @return list<int>
     */
    private function partyButtons(string $html): array
    {
        preg_match_all('/data-party="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    public function test_changing_offers_the_same_sizes_as_booking_and_the_way_for_large_groups(): void
    {
        ['setup' => $setup, 'reservation' => $reservation] = $this->reservation();

        $booking = $this->bookingPage($setup);
        $change = $this->changePage($reservation);

        $this->assertSame(range(2, 10), $this->partyButtons($booking));
        $this->assertSame(range(2, 10), $this->partyButtons($change));
        $this->assertStringContainsString('id="partyMoreBtn"', $change);
        $this->assertStringContainsString('Für Gruppen ab 11 Personen', $change);
        $this->assertStringContainsString('mailto:gruppen@example.test', $change);
    }

    public function test_without_a_group_address_there_is_no_more_button(): void
    {
        ['reservation' => $reservation] = $this->reservation(['large_group_email' => null]);

        $change = $this->changePage($reservation);

        $this->assertStringNotContainsString('id="partyMoreBtn"', $change);
        $this->assertStringNotContainsString('Für Gruppen ab', $change);
    }

    public function test_the_number_of_buttons_comes_from_the_settings_on_both_pages(): void
    {
        config(['swayy.booking.party_buttons' => 4]);
        ['setup' => $setup, 'reservation' => $reservation] = $this->reservation();

        $this->assertSame([2, 3, 4, 5], $this->partyButtons($this->bookingPage($setup)));
        $this->assertSame([2, 3, 4, 5], $this->partyButtons($this->changePage($reservation)));
    }

    public function test_a_smaller_largest_group_limits_both_pages(): void
    {
        ['setup' => $setup, 'reservation' => $reservation] = $this->reservation(['max_party_online' => 6]);

        $this->assertSame(range(2, 6), $this->partyButtons($this->bookingPage($setup)));
        $this->assertSame(range(2, 6), $this->partyButtons($this->changePage($reservation)));
    }

    public function test_a_booked_size_above_the_buttons_stays_selectable_and_can_be_kept(): void
    {
        Mail::fake();
        ['reservation' => $reservation] = $this->reservation([], 15);

        $this->assertSame([...range(2, 10), 15], $this->partyButtons($this->changePage($reservation)));

        $url = '/reservation/'.$reservation->code.'/reschedule/'.$reservation->manage_token;
        $this->post($url, ['date' => '2026-12-05', 'time' => '19:00', 'party_size' => 15])
            ->assertSessionDoesntHaveErrors('party_size');

        $this->post($url, ['date' => '2026-12-05', 'time' => '19:00', 'party_size' => 31])
            ->assertSessionHasErrors(['party_size' => 'Online lässt sich die Reservierung auf 2 bis 30 Personen ändern. Bitte eine Zahl aus der Auswahl wählen oder den Betrieb direkt ansprechen.']);
    }

    public function test_a_booked_size_beyond_the_online_limit_is_not_offered_online(): void
    {
        ['reservation' => $reservation] = $this->reservation([], 35);

        $this->assertSame(range(2, 10), $this->partyButtons($this->changePage($reservation)));
    }
}

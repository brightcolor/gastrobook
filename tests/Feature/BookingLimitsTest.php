<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\OpeningHour;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Grenzen, die nur galten, wenn die Anwendung den Tisch selbst suchte.
 *
 * Sobald eine Buchung einen Tisch mitbrachte - oeffentlicher Tischplan,
 * interne Maske - lief sie am Zweig mit Vorlaufzeit, Buchungshorizont,
 * Platzlimit und Pufferzeit vorbei. Dieselbe Anfrage ohne Tisch wurde
 * abgelehnt, mit Tisch ging sie durch.
 */
class BookingLimitsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $setup
     */
    private function buchungsUrl(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
    }

    // ── Vorlaufzeit und Buchungshorizont ──────────────────────────────────

    public function test_a_chosen_table_does_not_bypass_the_lead_time(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_lead_minutes' => 120,
            'public_floorplan_enabled' => true,
        ]);
        $this->clearTenantContext();

        // Heute, kurz nach jetzt - aber auf einem gueltigen Rasterpunkt.
        $jetzt = CarbonImmutable::now($setup['location']->timezone);
        $gleich = $jetzt->addMinutes(30)->setTime((int) $jetzt->addMinutes(30)->format('H'), 30);

        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'date' => $gleich->toDateString(),
            'time' => $gleich->format('H:i'),
            'table_id' => $setup['tables'][0]->id,
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_chosen_table_does_not_bypass_the_booking_horizon(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'max_advance_days' => 7,
            'public_floorplan_enabled' => true,
        ]);
        $this->clearTenantContext();

        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(60)->toDateString(),
            'table_id' => $setup['tables'][0]->id,
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('reservations', 0);
    }

    // ── Platzlimit ────────────────────────────────────────────────────────

    public function test_a_chosen_table_does_not_bypass_the_covers_limit(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 8], ['min' => 1, 'max' => 8]]);
        $setup['location']->settings()->update([
            'capacity_mode' => 'hybrid',
            'max_covers_per_slot' => 4,
            'public_floorplan_enabled' => true,
            'max_party_online' => 20,
        ]);
        $this->clearTenantContext();

        $daten = $this->formular($setup, ['party_size' => 8, 'table_id' => $setup['tables'][0]->id]);

        $this->post($this->buchungsUrl($setup), $daten)->assertSessionHasErrors();
        $this->assertDatabaseCount('reservations', 0);
    }

    // ── Pufferzeit ────────────────────────────────────────────────────────

    public function test_a_chosen_table_respects_the_turnaround_buffer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['location']->settings()->update([
            'buffer_minutes' => 30,
            'public_floorplan_enabled' => true,
        ]);

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(2);
        $frueher = $tag->setTime(19, 0);
        $vorlaeufer = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $frueher->toDateString(),
            'start_at' => $frueher->utc(),
            'end_at' => $frueher->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Vorlaeufer',
        ]);
        $vorlaeufer->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        // 21:00 endet der Vorlaeufer, 21:30 waere frei - 21:00 nicht, weil
        // dazwischen 30 Minuten Wende- und Reinigungszeit stehen.
        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'date' => $tag->toDateString(),
            'time' => '21:00',
            'table_id' => $setup['tables'][0]->id,
        ]))->assertSessionHasErrors();

        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    // ── Tischplan abgeschaltet ────────────────────────────────────────────

    /**
     * Wer den oeffentlichen Tischplan ausschaltet, geht davon aus, dass Gaeste
     * keinen Tisch aussuchen. Das Feld wurde trotzdem angenommen - per
     * Formular liess sich damit jeder Tisch belegen und die gesamte
     * Tischzuteilung aushebeln.
     */
    public function test_a_table_id_is_ignored_when_the_public_floor_plan_is_off(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2], ['min' => 1, 'max' => 8]]);
        $setup['location']->settings()->update(['public_floorplan_enabled' => false]);
        $this->clearTenantContext();

        // Der grosse Tisch waere die Wahl des Gastes; die Zuteilung nimmt fuer
        // zwei Personen den kleinen.
        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'table_id' => $setup['tables'][1]->id,
        ]))->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->sole();
        $this->assertFalse((bool) $reservation->table_chosen_by_guest);
        $this->assertSame([$setup['tables'][0]->id], $reservation->tables->pluck('id')->all());
    }

    // ── Fenster ueber Mitternacht ─────────────────────────────────────────

    /**
     * Bar mit 18:00-02:00: Der Slot "00:00" gehoert zum Folgetag. Vorher gab
     * die Slot-Antwort nur die Uhrzeit her, das Formular baute daraus den
     * angefragten Tag 00:00 - und buchte damit die Nacht davor, 24 Stunden vor
     * dem angeklickten Slot.
     */
    public function test_a_slot_after_midnight_carries_the_following_day(): void
    {
        $setup = $this->createTenantSetup();
        OpeningHour::where('location_id', $setup['location']->id)
            ->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(2);

        $antwort = $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/slots?date='.$tag->toDateString().'&party_size=2')->assertOk();

        $daten = $antwort->json();
        $this->assertContains('00:00', $daten['slots']);
        $this->assertSame($tag->addDay()->toDateString(), $daten['slot_dates']['00:00']);
        $this->assertSame($tag->toDateString(), $daten['slot_dates']['19:00']);
    }

    public function test_booking_a_slot_after_midnight_lands_on_the_right_night(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        OpeningHour::where('location_id', $setup['location']->id)
            ->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(2);

        $this->post($this->buchungsUrl($setup), $this->formular($setup, [
            'date' => $tag->toDateString(),
            'slot_date' => $tag->addDay()->toDateString(),
            'time' => '00:00',
        ]))->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->sole();
        $this->assertSame($tag->addDay()->toDateString(), $reservation->reservation_date->toDateString());
    }

    // ── Salon ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $setup
     * @return array{0: Service, 1: StaffMember}
     */
    private function salon(array $setup): array
    {
        $setup['tenant']->update(['type' => 'salon']);

        $service = Service::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Schnitt',
            'duration_minutes' => 60,
            'price_minor' => 4000,
            'is_active' => true,
        ]);

        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Frau Berg',
            'is_active' => true,
        ]);
        $staff->services()->attach($service->id);

        return [$service, $staff];
    }

    public function test_a_salon_appointment_cannot_be_booked_in_the_past(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        [$service] = $this->salon($setup);
        $this->clearTenantContext();

        $this->post($this->buchungsUrl($setup), [
            'service_ids' => [$service->id],
            'date' => '2020-05-16',
            'time' => '14:00',
            'name' => 'Frau Kessler',
            'email' => 'kessler@example.test',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_salon_appointment_respects_the_lead_time(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        [$service] = $this->salon($setup);
        $setup['location']->settings()->update(['min_lead_minutes' => 240]);
        $this->clearTenantContext();

        $jetzt = CarbonImmutable::now($setup['location']->timezone);
        $gleich = $jetzt->addMinutes(30);

        $this->post($this->buchungsUrl($setup), [
            'service_ids' => [$service->id],
            'date' => $gleich->toDateString(),
            'time' => $gleich->format('H:i'),
            'name' => 'Frau Kessler',
            'email' => 'kessler@example.test',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('time');

        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * Die Verfuegbarkeit der Mitarbeiterin wurde VOR dem Anlegen geprueft und
     * das Anlegen selbst mit skip_availability_check aufgerufen. Die Sperre
     * bestimmte damit nur, wer zuerst schreibt - beide Anfragen beruhten auf
     * demselben veralteten "ist frei".
     */
    public function test_a_staff_member_cannot_be_booked_twice_for_the_same_slot(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        [$service, $staff] = $this->salon($setup);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(2);
        $daten = [
            'service_ids' => [$service->id],
            'staff_member_id' => $staff->id,
            'date' => $tag->toDateString(),
            'time' => '14:00',
            'name' => 'Frau Kessler',
            'email' => 'kessler@example.test',
            'privacy_accepted' => '1',
        ];

        $this->post($this->buchungsUrl($setup), $daten)->assertRedirect();
        $this->post($this->buchungsUrl($setup), array_merge($daten, [
            'name' => 'Herr Lorenz', 'email' => 'lorenz@example.test',
        ]))->assertSessionHasErrors();

        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }
}

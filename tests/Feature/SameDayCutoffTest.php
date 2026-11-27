<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\OpeningHour;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\OnlineBookingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Annahmeschluss für den laufenden Tag (location_settings.same_day_cutoff).
 *
 * Ab der eingestellten Uhrzeit nimmt die Online-Buchung für den laufenden Tag
 * nichts mehr an, auch nicht für die Uhrzeiten nach Mitternacht, die noch zu
 * den heutigen Öffnungszeiten gehören. Das Team trägt weiter ein.
 */
class SameDayCutoffTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NOW = '2026-12-02 12:30';

    /**
     * @return array<string, mixed>
     */
    private function restaurant(?string $cutoff = '12:00'): array
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'Europe/Berlin'));
        $setup = $this->createTenantSetup();
        $setup['location']->update(['phone' => '+49 30 1234567']);
        $setup['location']->settings()->update(['same_day_cutoff' => $cutoff]);
        $this->clearTenantContext();

        return $setup;
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function slots(array $setup, string $date, int $partySize = 2): array
    {
        return $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/slots?date='.$date.'&party_size='.$partySize)->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $fields
     */
    private function book(array $setup, array $fields)
    {
        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;

        return $this->from($url)->post($url, array_merge([
            'party_size' => 2, 'name' => 'Frau Kessler', 'email' => 'kessler@example.test',
            'phone' => '+49 30 000000', 'privacy_accepted' => '1',
        ], $fields));
    }

    public function test_after_the_cutoff_today_shows_no_times_and_explains_why(): void
    {
        $setup = $this->restaurant();

        $today = $this->slots($setup, '2026-12-02');
        $this->assertSame([], $today['slots']);
        $this->assertSame(
            'Schön, dass Sie heute kommen möchten! Online buchen geht für heute nur bis 12:00 Uhr. Kommen Sie aber gern einfach vorbei – vielleicht findet sich noch ein Plätzchen für Sie, versprechen können wir es leider nicht. Sie können uns auch anrufen: +49 30 1234567. Oder suchen Sie sich einen anderen Tag aus. Wir freuen uns auf Sie!',
            $today['day_notice']['text'],
        );
        $this->assertSame('2026-12-03', $today['day_notice']['next_date']);
        $this->assertSame('Zu morgen', $today['day_notice']['jump_label']);

        $this->assertContains('19:00', $this->slots($setup, '2026-12-03')['slots']);
    }

    public function test_with_du_and_without_phone_the_guest_is_invited_to_drop_by(): void
    {
        $setup = $this->restaurant();
        $setup['location']->update(['phone' => null]);
        $setup['location']->settings()->update(['guest_address' => 'du']);

        $this->assertSame(
            'Schön, dass du heute kommen möchtest! Online buchen geht für heute nur bis 12:00 Uhr. Komm aber gern einfach vorbei – vielleicht findet sich noch ein Plätzchen für dich, versprechen können wir es leider nicht. Oder such dir einen anderen Tag aus. Wir freuen uns auf dich!',
            $this->slots($setup, '2026-12-02')['day_notice']['text'],
        );
    }

    public function test_without_walk_ins_nobody_is_invited_to_drop_by(): void
    {
        $setup = $this->restaurant();
        $setup['location']->settings()->update(['walkins_enabled' => false, 'guest_address' => 'du']);

        $this->assertSame(
            'Schön, dass du heute kommen möchtest! Online buchen geht für heute nur bis 12:00 Uhr. Ruf uns gern an: +49 30 1234567. Oder such dir einen anderen Tag aus. Wir freuen uns auf dich!',
            $this->slots($setup, '2026-12-02')['day_notice']['text'],
        );
    }

    public function test_before_the_cutoff_today_is_bookable(): void
    {
        $setup = $this->restaurant('13:00');

        $this->assertContains('19:00', $this->slots($setup, '2026-12-02')['slots']);
    }

    public function test_without_a_cutoff_nothing_changes(): void
    {
        $setup = $this->restaurant(null);

        $this->assertContains('19:00', $this->slots($setup, '2026-12-02')['slots']);
    }

    public function test_the_night_after_midnight_belongs_to_today(): void
    {
        $setup = $this->restaurant();
        OpeningHour::where('location_id', $setup['location']->id)->update(['opens_at' => '18:00', 'closes_at' => '02:00']);

        // Die Nacht von heute: 00:00 am 3. Dezember gehört zum 2. Dezember.
        $this->book($setup, ['date' => '2026-12-02', 'slot_date' => '2026-12-03', 'time' => '00:00'])
            ->assertSessionHasErrors();
        $this->assertStringContainsString('bis 12:00 Uhr', implode(' ', session('errors')->all()));
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Der Abend des 3. Dezember ist ein neuer Tag, samt seiner Nacht.
        $tomorrow = $this->slots($setup, '2026-12-03');
        $this->assertContains('19:00', $tomorrow['slots']);
        $this->assertContains('00:00', $tomorrow['slots']);
    }

    public function test_after_midnight_the_rest_of_the_night_stays_closed(): void
    {
        $setup = $this->restaurant();
        OpeningHour::where('location_id', $setup['location']->id)->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $this->travelTo(CarbonImmutable::parse('2026-12-03 00:10', 'Europe/Berlin'));
        $window = app(OnlineBookingWindow::class);
        $location = $setup['location']->fresh();

        // 00:30 gehört zur Nacht des 2. Dezember, dessen Annahmeschluss vorbei ist.
        $this->assertSame('2026-12-02', $window->serviceDate($location, CarbonImmutable::parse('2026-12-03 00:30', 'Europe/Berlin'))->toDateString());
        $this->assertSame('same_day_closed', $window->blockReason($location, CarbonImmutable::parse('2026-12-03 00:30', 'Europe/Berlin')));
        // Der Abend des 3. Dezember ist bis zu dessen Annahmeschluss offen.
        $this->assertNull($window->blockReason($location, CarbonImmutable::parse('2026-12-03 19:00', 'Europe/Berlin')));
    }

    public function test_booking_today_after_the_cutoff_is_refused_with_the_reason(): void
    {
        Mail::fake();
        $setup = $this->restaurant();

        $response = $this->book($setup, ['date' => '2026-12-02', 'time' => '19:00']);

        $response->assertSessionHasErrors();
        $this->assertStringContainsString('bis 12:00 Uhr', implode(' ', session('errors')->all()));
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        $this->book($setup, ['date' => '2026-12-03', 'time' => '19:00'])->assertSessionHasNoErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_another_cutoff_time_applies_as_set(): void
    {
        Mail::fake();
        $setup = $this->restaurant('16:30');

        $this->book($setup, ['date' => '2026-12-02', 'time' => '19:00'])->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-12-02 16:30', 'Europe/Berlin'));
        $this->assertSame([], $this->slots($setup, '2026-12-02')['slots']);
    }

    public function test_the_guest_cannot_move_a_booking_into_today_after_the_cutoff(): void
    {
        $setup = $this->restaurant();
        $start = CarbonImmutable::parse('2026-12-04 19:00', 'Europe/Berlin');
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(), 'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed, 'source' => 'online', 'guest_name_snapshot' => 'Umbucher',
        ]);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        $this->post('/reservation/'.$reservation->code.'/reschedule/'.$reservation->manage_token, [
            'date' => '2026-12-02', 'time' => '19:00', 'party_size' => 2,
        ])->assertSessionHasErrors('time');

        $this->assertSame('2026-12-04', $reservation->fresh()->reservation_date->toDateString());
    }

    public function test_the_team_still_books_today(): void
    {
        $setup = $this->restaurant();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations', [
            'date' => '2026-12-02', 'time' => '19:00', 'party_size' => 2, 'name' => 'Herr Brandt',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_a_salon_shows_no_appointments_today_after_the_cutoff(): void
    {
        $setup = $this->restaurant();
        $setup['tenant']->update(['type' => 'salon']);
        $service = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Schnitt', 'duration_minutes' => 30, 'price_minor' => 3000, 'is_active' => true,
        ]);
        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Anna', 'is_active' => true,
        ]);
        $staff->services()->attach($service->id);
        $this->clearTenantContext();

        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/slots?service_ids[]='.$service->id.'&date=';

        $today = $this->getJson($url.'2026-12-02')->assertOk()->json();
        $this->assertSame([], $today['slots']);
        $this->assertStringContainsString('bis 12:00 Uhr', $today['day_notice']['text']);
        // Im Salon gibt es keinen Walk-in-Schalter, also auch keine Einladung zum Vorbeikommen.
        $this->assertStringNotContainsString('einfach vorbei', $today['day_notice']['text']);

        $this->assertNotSame([], $this->getJson($url.'2026-12-03')->assertOk()->json('slots'));
    }

    public function test_the_setting_is_saved_checked_and_cleared(): void
    {
        $setup = $this->restaurant(null);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $rules = [
            'min_lead_minutes' => 60, 'max_advance_days' => 60, 'min_party_online' => 1, 'max_party_online' => 8,
            'cancellation_deadline_minutes' => 120, 'modification_deadline_minutes' => 120,
            'booking_confirmation_mode' => 'auto', 'require_email_confirmation' => '1', 'waitlist_enabled' => '1', 'walkins_enabled' => '1',
        ];

        $this->actingAs($owner)->putJson('/admin/settings/rules', $rules + ['same_day_cutoff' => '14:30'])->assertOk();
        $this->assertSame('14:30', $setup['location']->settings()->first()->same_day_cutoff);

        $this->actingAs($owner)->putJson('/admin/settings/rules', $rules + ['same_day_cutoff' => '25:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.same_day_cutoff.0', 'Bitte den Annahmeschluss als Uhrzeit eintragen, zum Beispiel 12:00, oder das Feld leer lassen.');

        $this->actingAs($owner)->putJson('/admin/settings/rules', $rules + ['same_day_cutoff' => ''])->assertOk();
        $this->assertNull($setup['location']->settings()->first()->same_day_cutoff);
    }
}

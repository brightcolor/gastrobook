<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Reservierungsbuch: stornierte oder abgelehnte Reservierungen wieder freigeben,
 * aktive durch das Team in Datum, Uhrzeit und Personenzahl ändern.
 */
class ReservationReopenAndEditTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function lifecycle(): ReservationLifecycleService
    {
        return app(ReservationLifecycleService::class);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $extra
     */
    private function book(array $setup, string $time = '19:00', int $party = 2, array $extra = [], int $inDays = 2): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays($inDays)
            ->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));

        $reservation = $this->lifecycle()->create($setup['location'], $extra + [
            'party_size' => $party,
            'start_local' => $start,
            'source' => 'manual',
            'guest_name' => 'Gast',
            'guest_email' => 'gast@example.test',
        ]);
        $this->clearTenantContext();

        return $reservation;
    }

    private function cancel(Reservation $reservation, ReservationStatus $as = ReservationStatus::CancelledByRestaurant): Reservation
    {
        return $this->lifecycle()->transition($reservation, $as, null, 'user');
    }

    private function mails(Reservation $reservation, string $template): int
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', $template)
            ->count();
    }

    // ── Wieder freigeben: Dienst ───────────────────────────────────────────

    public function test_a_cancelled_reservation_can_be_reopened_and_keeps_its_tables(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->book($setup);
        $tableIds = $reservation->tables->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($tableIds);

        $this->cancel($reservation);
        $this->assertNotNull($reservation->fresh()->cancelled_at);

        $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, 'user', 'Wieder freigegeben');

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame($tableIds, $fresh->tables->pluck('id')->sort()->values()->all());
    }

    public function test_a_rejected_reservation_can_be_confirmed_again(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->book($setup);
        // Ablehnen gibt es nur für Anfragen.
        $reservation->update(['status' => ReservationStatus::Requested]);
        $this->cancel($reservation->fresh(), ReservationStatus::Rejected);

        $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, 'user');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_reopening_is_refused_when_the_place_was_taken_meanwhile(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $alt = $this->book($setup);
        $this->cancel($alt);
        $this->book($setup); // nimmt den einzigen Tisch zur selben Zeit

        try {
            $this->lifecycle()->transition($alt->fresh(), ReservationStatus::Confirmed, null, 'user');
            $this->fail('Die Freigabe hätte scheitern müssen.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
            $this->assertStringContainsString('Tisch', $e->errors()['status'][0]);
        }

        $this->assertSame(ReservationStatus::CancelledByRestaurant, $alt->fresh()->status);
    }

    public function test_reopening_picks_another_free_table_when_the_old_one_is_taken(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $alt = $this->book($setup);
        $alterTisch = $alt->tables->first()->id;
        $this->cancel($alt);

        // Dieselbe Zeit, derselbe Tisch, von Hand gesetzt.
        $this->book($setup, extra: ['table_ids' => [$alterTisch]]);

        $this->lifecycle()->transition($alt->fresh(), ReservationStatus::Confirmed, null, 'user');

        $fresh = $alt->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
        $this->assertNotContains($alterTisch, $fresh->tables->pluck('id')->all());
        $this->assertCount(1, $fresh->tables);
    }

    public function test_reopening_with_override_double_books_the_old_table(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $alt = $this->book($setup);
        $this->cancel($alt);
        $this->book($setup);

        $this->lifecycle()->transition($alt->fresh(), ReservationStatus::Confirmed, null, 'user', null, null, null, true);

        $this->assertSame(ReservationStatus::Confirmed, $alt->fresh()->status);
        $this->assertCount(1, $alt->fresh()->tables);
    }

    public function test_only_staff_can_reopen(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();

        foreach (['system', 'guest'] as $actorType) {
            $reservation = $this->book($setup);
            $this->cancel($reservation);

            try {
                $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, $actorType);
                $this->fail("Als {$actorType} hätte die Freigabe scheitern müssen.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
            $this->assertSame(ReservationStatus::CancelledByRestaurant, $reservation->fresh()->status);
        }
    }

    public function test_a_reservation_in_the_past_cannot_be_reopened(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->book($setup);
        $this->cancel($reservation);
        $reservation->update([
            'start_at' => now()->subDays(2)->utc(),
            'end_at' => now()->subDays(2)->addHours(2)->utc(),
        ]);

        $this->expectException(ValidationException::class);
        $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, 'user');
    }

    public function test_a_reservation_with_a_refunded_deposit_cannot_be_reopened(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->book($setup);
        $this->cancel($reservation);
        $reservation->update(['payment_status' => 'refunded']);

        try {
            $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, 'user');
            $this->fail('Die Freigabe hätte scheitern müssen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Rückerstattung', $e->errors()['status'][0]);
        }
    }

    public function test_reopening_a_guest_cancellation_takes_it_back_from_the_guest_profile(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->book($setup);
        $guestId = $reservation->guest_id;
        $this->assertNotNull($guestId);

        $this->cancel($reservation, ReservationStatus::CancelledByGuest);
        $this->assertSame(1, (int) $reservation->fresh()->guest->cancellation_count);

        $this->lifecycle()->transition($reservation->fresh(), ReservationStatus::Confirmed, null, 'user');

        $this->assertSame(0, (int) $reservation->fresh()->guest->cancellation_count);
    }

    // ── Wieder freigeben: Oberfläche ───────────────────────────────────────

    public function test_the_reservation_book_offers_the_matching_buttons(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $aktiv = $this->book($setup, '18:00');
        $storniert = $this->book($setup, '20:00');
        $this->cancel($storniert);

        $html = $this->actingAs($admin)->get('/admin/reservations?from=2000-01-01&to=2100-01-01')->assertOk()->getContent();

        $this->assertStringContainsString('data-edit-open', $html);
        $this->assertStringContainsString('/admin/reservations/'.$aktiv->id.'/reschedule', $html);
        $this->assertStringContainsString('data-reopen-open', $html);
        $this->assertStringContainsString('/admin/reservations/'.$storniert->id.'/transition', $html);
    }

    public function test_the_detail_page_shows_the_matching_form(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $aktiv = $this->book($setup, '18:00');
        $storniert = $this->book($setup, '20:00');
        $this->cancel($storniert);

        $this->actingAs($admin)->get('/admin/reservations/'.$aktiv->id)
            ->assertOk()
            ->assertSee('Termin ändern')
            ->assertSee('/admin/reservations/'.$aktiv->id.'/reschedule', false)
            ->assertDontSee('Wieder freigeben');

        $html = $this->actingAs($admin)->get('/admin/reservations/'.$storniert->id)
            ->assertOk()
            ->assertSee('Wieder freigeben')
            ->assertDontSee('Termin ändern')
            ->getContent();
        // Das Formular zum Freigeben ersetzt den Knopf „Bestätigen“ der Aktionsliste.
        $this->assertSame(1, substr_count($html, 'name="status" value="confirmed"'));
    }

    public function test_the_reopen_route_confirms_and_mails_the_guest_unless_told_otherwise(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();

        $mit = $this->book($setup, '18:00');
        $ohne = $this->book($setup, '20:30');
        $this->cancel($mit);
        $this->cancel($ohne);
        $vorherMit = $this->mails($mit, 'reservation_confirmed');
        $vorherOhne = $this->mails($ohne, 'reservation_confirmed');

        $this->actingAs($admin)->post('/admin/reservations/'.$mit->id.'/transition', ['status' => 'confirmed', 'notify' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Reservierung wieder freigegeben.');
        $this->actingAs($admin)->post('/admin/reservations/'.$ohne->id.'/transition', ['status' => 'confirmed', 'notify' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(ReservationStatus::Confirmed, $mit->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $ohne->fresh()->status);
        $this->assertSame($vorherMit + 1, $this->mails($mit, 'reservation_confirmed'));
        $this->assertSame($vorherOhne, $this->mails($ohne, 'reservation_confirmed'));
    }

    public function test_the_reopen_route_shows_the_reason_when_the_place_is_gone(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $alt = $this->book($setup);
        $this->cancel($alt);
        $this->book($setup);

        $this->actingAs($admin)->post('/admin/reservations/'.$alt->id.'/transition', ['status' => 'confirmed'])
            ->assertSessionHasErrors('status');

        $this->assertSame(ReservationStatus::CancelledByRestaurant, $alt->fresh()->status);
    }

    public function test_overriding_the_availability_check_needs_the_right(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $host = $this->createMember($setup['tenant'], 'host');
        $host->forceFill(['current_location_id' => $setup['location']->id])->save();
        $alt = $this->book($setup);
        $this->cancel($alt);
        $this->book($setup);

        $this->actingAs($host)->post('/admin/reservations/'.$alt->id.'/transition', ['status' => 'confirmed', 'force' => '1'])
            ->assertForbidden();

        $this->assertSame(ReservationStatus::CancelledByRestaurant, $alt->fresh()->status);
    }

    public function test_a_bulk_action_never_reopens_reservations(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);
        $this->cancel($reservation);

        $this->actingAs($admin)->post('/admin/reservations/bulk-transition', ['ids' => [$reservation->id], 'status' => 'confirmed'])
            ->assertSessionHas('success');

        $this->assertSame(ReservationStatus::CancelledByRestaurant, $reservation->fresh()->status);
    }

    // ── Termin ändern ──────────────────────────────────────────────────────

    public function test_the_team_can_move_a_reservation_and_the_tables_stay_when_free(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup, '19:00', 2, ['table_ids' => [$setup['tables'][2]->id]]);
        $tz = $setup['location']->timezone;
        $neu = CarbonImmutable::now($tz)->addDays(3)->setTime(13, 30);

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => $neu->toDateString(), 'time' => '13:30', 'party_size' => 3, 'notify' => '0',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $reservation->fresh();
        $this->assertTrue($fresh->localStart()->equalTo($neu));
        $this->assertSame(3, (int) $fresh->party_size);
        $this->assertSame([$setup['tables'][2]->id], $fresh->tables->pluck('id')->all());
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
    }

    public function test_moving_mails_the_guest_only_when_asked_to(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);
        $day = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString();
        $vorher = $this->mails($reservation, 'reservation_confirmed');

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => $day, 'time' => '14:00', 'notify' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertSame($vorher, $this->mails($reservation, 'reservation_confirmed'));

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => $day, 'time' => '15:00', 'notify' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame($vorher + 1, $this->mails($reservation, 'reservation_confirmed'));
    }

    public function test_the_team_is_not_held_to_the_online_limits_of_the_guest(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_party_online' => 4, 'min_lead_minutes' => 100000]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);

        // 6 Personen übersteigen die Online-Grenze von 4, der Termin liegt innerhalb des Vorlaufs.
        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addHours(2)->toDateString(),
            'time' => '19:30', 'party_size' => 6, 'notify' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, (int) $reservation->fresh()->party_size);
    }

    public function test_moving_outside_the_opening_hours_is_refused_with_a_message_for_the_team(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);
        $vorher = $reservation->fresh()->start_at;

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '04:00', 'notify' => '0',
        ])->assertSessionHasErrors(['time' => 'Zu dieser Uhrzeit hat der Betrieb nicht geöffnet. Wähle eine Zeit innerhalb der Öffnungszeiten.']);

        $this->assertTrue($reservation->fresh()->start_at->equalTo($vorher));
    }

    public function test_a_cancelled_reservation_cannot_be_moved_and_the_message_names_the_way(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);
        $this->cancel($reservation);

        $response = $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '14:00',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('wieder frei', session('errors')->first('status'));
    }

    public function test_moving_to_the_same_time_is_refused(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);
        $start = $reservation->fresh()->localStart();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'), 'party_size' => $reservation->party_size,
        ])->assertSessionHasErrors('time');
    }

    public function test_moving_needs_the_right_to_update_reservations(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $readonly->forceFill(['current_location_id' => $setup['location']->id])->save();
        $reservation = $this->book($setup);

        $this->actingAs($readonly)->post('/admin/reservations/'.$reservation->id.'/reschedule', [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '14:00',
        ])->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OpeningHour;
use App\Models\WaitlistEntry;
use App\Services\ReservationAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Walk-ins entstehen an der Uhr, nicht am Slot-Raster.
 *
 * Der Gast steht in der Tuer, jemand tippt Tisch und Personenzahl ein - die
 * Uhrzeit ist dabei die echte Wanduhrzeit inklusive Sekunden. Die
 * Rasterpruefung fuer oeffentliche Buchungen darf hier nicht greifen, sonst
 * scheitert JEDER Walk-in mit "Zu dieser Uhrzeit haben wir leider
 * geschlossen", obwohl der Betrieb offen ist.
 *
 * Der vorhandene BoardTest traf den Fehler nicht: Er friert die Zeit auf
 * 14:00:00 Ortszeit ein und liegt damit zufaellig exakt auf einem Rasterpunkt.
 * Die Zeiten hier tun das bewusst nicht.
 */
class WalkInTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Ortszeit Europe/Berlin = UTC+2 im Juni. */
    private function travelToLocal(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 '.$localTime, 'Europe/Berlin')->utc());
    }

    // ── Der eigentliche Zweck ─────────────────────────────────────────────

    public function test_a_walk_in_at_an_odd_minute_is_seated(): void
    {
        $this->travelToLocal('19:07:23');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
            'name' => 'Spontan',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('reservations', [
            'location_id' => $setup['location']->id,
            'source' => 'walk_in',
            'guest_name_snapshot' => 'Spontan',
        ]);
    }

    /**
     * Kurz vor Feierabend gibt es gar keinen Rasterpunkt mehr: slotStarts endet
     * bei `closes - duration`. Wer um 22:45 noch hereinkommt, bekommt trotzdem
     * einen Tisch - der Betrieb hat bis 23:00 offen.
     */
    public function test_a_walk_in_shortly_before_closing_is_seated(): void
    {
        $this->travelToLocal('22:45:00');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
        ])->assertOk()->assertJson(['ok' => true]);
    }

    // ── Die Grenze bleibt bestehen ────────────────────────────────────────

    public function test_a_walk_in_outside_opening_hours_is_still_rejected(): void
    {
        $this->travelToLocal('03:30:00');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $antwort = $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
        ]);

        // 422 mit Meldung, nicht Weiterleitung: Der Tischplan fragt per fetch
        // an und kann eine Weiterleitung nicht anzeigen.
        $this->assertSame(422, $antwort->getStatusCode());
        $this->assertNotEmpty($antwort->json('message'));
        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * Eine Bar mit Fenster 18:00-02:00: Die halbe Stunde nach Mitternacht
     * gehoert noch zum Vortag und ist offen.
     */
    public function test_a_walk_in_after_midnight_falls_into_the_previous_days_window(): void
    {
        $this->travelToLocal('00:40:00');

        $setup = $this->createTenantSetup();
        OpeningHour::where('location_id', $setup['location']->id)
            ->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
        ])->assertOk()->assertJson(['ok' => true]);
    }

    /**
     * Die oeffentliche Buchung bleibt am Raster - sonst koennte jemand per
     * Formular eine Zeit buchen, die nie angeboten wurde.
     */
    public function test_the_public_booking_form_still_requires_a_grid_time(): void
    {
        $this->travelToLocal('12:00:00');

        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $ergebnis = app(ReservationAvailabilityService::class)->checkExact(
            $setup['location'],
            CarbonImmutable::parse('2026-06-16 19:07', 'Europe/Berlin'),
            2
        );

        $this->assertFalse($ergebnis['available']);
        $this->assertSame('outside_opening_hours', $ergebnis['reason']);
    }

    // ── Derselbe Fehler auf dem Wartelisten-Weg ───────────────────────────

    public function test_seating_a_waitlist_guest_right_now_works(): void
    {
        $this->travelToLocal('19:07:23');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $entry = WaitlistEntry::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_name' => 'Wartender Gast',
            'guest_email' => 'wartend@example.test',
            'party_size' => 2,
            'desired_date' => '2026-06-15',
            'desired_time_from' => '19:00',
            'desired_time_to' => '21:00',
            'status' => 'waiting',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$entry->id.'/seat')
            ->assertRedirect();

        $this->assertSame('accepted', $entry->fresh()->status);
        $this->assertDatabaseHas('reservations', [
            'location_id' => $setup['location']->id,
            'guest_name_snapshot' => 'Wartender Gast',
        ]);
    }
}

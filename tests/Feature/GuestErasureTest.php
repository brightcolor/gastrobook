<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\RunRetentionPolicies;
use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestNote;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\Tenant;
use App\Services\GuestPrivacyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Loeschung heisst Loeschung - auch an den Stellen, die nicht das Gastprofil
 * sind.
 */
class GuestErasureTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @return array{0: Guest, 1: Reservation}
     */
    private function guestWithHistory(array $setup): array
    {
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier',
            'email' => 'klara@example.test', 'phone' => '+49 30 111111',
            'allergies' => 'Nüsse',
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->subWeek()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Completed,
            'source' => 'online',
            'guest_name_snapshot' => 'Klara Meier',
            'guest_email_snapshot' => 'klara@example.test',
            'internal_note' => 'Beschwert sich gern, Tisch am Fenster meiden.',
        ]);

        return [$guest, $reservation];
    }

    /**
     * Das Aenderungsprotokoll haelt Vorname, Nachname, E-Mail, Telefon,
     * Geburtstag und Allergien im Klartext - und zeigt sie im Betrieb auch an.
     * Nach der "Loeschung" las sie dort jeder weiter.
     */
    public function test_erasure_reaches_the_audit_log(): void
    {
        $setup = $this->createTenantSetup();
        [$guest] = $this->guestWithHistory($setup);

        AuditLog::create([
            'tenant_id' => $setup['tenant']->id,
            'action' => 'guest.updated',
            'entity_type' => Guest::class,
            'entity_id' => $guest->id,
            'old_values' => ['phone' => '+49 30 111111', 'allergies' => 'Nüsse'],
            'new_values' => ['phone' => '+49 30 222222', 'allergies' => 'Nüsse, Sellerie'],
        ]);

        app(GuestPrivacyService::class)->anonymize($guest);

        $eintrag = AuditLog::withoutGlobalScopes()
            ->where('entity_type', Guest::class)
            ->where('entity_id', $guest->id)
            ->where('action', 'guest.updated')
            ->sole();

        // Aktion, Zeitpunkt und Benutzer bleiben nachweisbar, die Werte nicht.
        $this->assertNull($eintrag->old_values);
        $this->assertNull($eintrag->new_values);
        $this->assertSame('guest.updated', $eintrag->action);
    }

    /**
     * Die interne Notiz an der Reservierung ist genau die Stelle, an der der
     * Betrieb notiert, was er ueber den Gast weiss.
     */
    public function test_erasure_reaches_internal_notes(): void
    {
        $setup = $this->createTenantSetup();
        [$guest, $reservation] = $this->guestWithHistory($setup);

        GuestNote::create([
            'tenant_id' => $setup['tenant']->id,
            'guest_id' => $guest->id,
            'body' => 'Sensibler Vermerk',
            'is_sensitive' => true,
        ]);
        ReservationNote::create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'body' => 'Zahlt bar, will keine Rechnung.',
        ]);

        app(GuestPrivacyService::class)->anonymize($guest);

        $this->assertNull($reservation->fresh()->internal_note);
        $this->assertSame(0, GuestNote::withoutGlobalScopes()->where('guest_id', $guest->id)->count());
        $this->assertSame(0, ReservationNote::withoutGlobalScopes()->where('reservation_id', $reservation->id)->count());
    }

    /**
     * Die Auskunft nach Art. 15 umfasst alles, was ueber den Betroffenen
     * gespeichert ist - gerade das Heiklere. Der Filter auf "nicht sensibel"
     * stammt aus der Sichtbarkeitsregel gegenueber Mitarbeitern.
     */
    public function test_the_data_export_includes_sensitive_notes(): void
    {
        $setup = $this->createTenantSetup();
        [$guest] = $this->guestWithHistory($setup);

        GuestNote::create([
            'tenant_id' => $setup['tenant']->id,
            'guest_id' => $guest->id,
            'body' => 'Sensibler Vermerk',
            'is_sensitive' => true,
        ]);

        $daten = app(GuestPrivacyService::class)->export($guest);

        $this->assertCount(1, $daten['notes']);
        $this->assertSame('Sensibler Vermerk', $daten['notes'][0]['body']);
        $this->assertTrue($daten['notes'][0]['is_sensitive']);
    }

    /**
     * Die Aufbewahrungsfrist ist eine gesetzliche Pflicht, keine Funktion des
     * Tarifs - sie lief bisher nur fuer aktive Betriebe.
     */
    public function test_retention_also_runs_for_a_cancelled_business(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'cancelled']);
        [$guest, $reservation] = $this->guestWithHistory($setup);
        $guest->forceFill([
            'last_visit_at' => now()->subYears(5),
            'created_at' => now()->subYears(5),
        ])->saveQuietly();
        // Ein Gast mit frischem Besuch bleibt stehen - die Buchung muss mit
        // altern, sonst prueft der Test die Frist gar nicht.
        $reservation->forceFill([
            'start_at' => now()->subYears(5),
            'end_at' => now()->subYears(5)->addHours(2),
        ])->saveQuietly();

        (new RunRetentionPolicies)->handle(app(GuestPrivacyService::class));

        $this->assertTrue((bool) $guest->fresh()->anonymized);
    }

    public function test_a_tenant_without_matching_guests_is_untouched(): void
    {
        $setup = $this->createTenantSetup();
        [$guest] = $this->guestWithHistory($setup);
        $guest->forceFill(['last_visit_at' => now()->subMonth()])->saveQuietly();

        (new RunRetentionPolicies)->handle(app(GuestPrivacyService::class));

        $this->assertFalse((bool) $guest->fresh()->anonymized);
        $this->assertSame('Klara', $guest->fresh()->first_name);
    }

    public function test_erasure_survives_a_tenant_without_locations(): void
    {
        $leer = Tenant::factory()->create(['plan_id' => Plan::factory()]);

        (new RunRetentionPolicies)->handle(app(GuestPrivacyService::class));

        $this->assertNotNull($leer->fresh());
    }
}

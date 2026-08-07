<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\DepositRule;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\Guest;
use App\Models\GuestMergeLog;
use App\Models\Location;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\TableBlock;
use App\Models\WebhookEndpoint;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use App\Services\GuestMergeService;
use App\Services\GuestPrivacyService;
use App\Services\MarketingCampaignService;
use App\Services\PaymentRequirementService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regressionstests zum Review vom 07.08.2026. Jeder Test haelt genau einen
 * bestaetigten Defekt fest, damit er nicht zurueckkommt.
 */
class ReviewFixesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // ── Mandantentrennung in der API ──────────────────────────────────────

    public function test_api_cannot_delete_a_webhook_of_another_tenant(): void
    {
        $mine = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $user = $this->createMember($mine['tenant'], 'tenant_admin');

        $foreign = WebhookEndpoint::create([
            'tenant_id' => $other['tenant']->id,
            'url' => 'https://93.184.216.34/hook',
            'secret' => 'geheim',
            'events' => ['*'],
        ]);
        $this->clearTenantContext();

        $token = $user->createToken('test', ['tenant:'.$mine['tenant']->id, 'webhooks:manage'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/webhooks/'.$foreign->id)
            ->assertNotFound();
        $this->assertSame(1, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_api_cannot_read_a_guest_of_another_tenant(): void
    {
        $mine = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $user = $this->createMember($mine['tenant'], 'tenant_admin');
        $foreign = Guest::factory()->create(['tenant_id' => $other['tenant']->id, 'last_name' => 'Fremd']);
        $this->clearTenantContext();

        $token = $user->createToken('test', ['tenant:'.$mine['tenant']->id, 'guests:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/guests/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_api_refuses_unknown_webhook_events(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $token = $user->createToken('test', ['tenant:'.$setup['tenant']->id, 'webhooks:manage'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/webhooks', [
                'url' => 'https://93.184.216.34/hook',
                'events' => ['reservation.create'], // Tippfehler
            ])->assertStatus(422);

        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    // ── Standortgrenze bei Anhaengen ──────────────────────────────────────

    public function test_attachment_of_another_location_stays_out_of_reach(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);

        // Benutzer nur fuer den zweiten Standort freigeschaltet.
        $user = $this->createMember($setup['tenant'], 'location_manager', false);
        DB::table('location_user')->insert([
            'location_id' => $second->id,
            'user_id' => $user->id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->update(['current_location_id' => $second->id]);

        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id, // der ANDERE Standort
        ]);
        $attachment = ReservationAttachment::create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'disk' => 'local',
            'path' => 'reservation-attachments/x/vertrag.pdf',
            'original_name' => 'vertrag.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
        $this->clearTenantContext();

        $this->actingAs($user)
            ->get('/admin/reservations/'.$reservation->id.'/attachments/'.$attachment->id)
            ->assertForbidden();
    }

    // ── DSGVO-Export und Notizrecht ───────────────────────────────────────

    public function test_data_export_needs_the_note_permission(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $marketing = $this->createMember($setup['tenant'], 'marketing_manager');
        $this->clearTenantContext();

        // marketing_manager darf exportieren, aber keine Notizen sehen –
        // der Export enthaelt sie, also muss er hier scheitern.
        $this->actingAs($marketing)->get('/admin/guests/'.$guest->id.'/export')->assertForbidden();
    }

    // ── Anzahlungsregeln ──────────────────────────────────────────────────

    public function test_the_party_size_rule_beats_the_blanket_rule(): void
    {
        $setup = $this->createTenantSetup();
        $base = [
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'type' => 'deposit',
            'amount_per_person_minor' => 500,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'is_active' => true,
        ];
        DepositRule::create($base + ['name' => 'Standard', 'min_party_size' => null]);
        DepositRule::create($base + ['name' => 'Große Gruppen', 'min_party_size' => 8, 'amount_per_person_minor' => 2000]);
        $this->clearTenantContext();

        $rule = app(PaymentRequirementService::class)->requirementFor(
            $setup['location'],
            CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0),
            10
        );

        $this->assertSame('Große Gruppen', $rule?->name);
    }

    // ── Zusammenfuehren ───────────────────────────────────────────────────

    public function test_merge_keeps_the_newer_marketing_decision(): void
    {
        $setup = $this->createTenantSetup();
        $keep = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth',
            'marketing_consent' => false, 'marketing_consent_at' => now()->subDay(), // Widerruf gestern
        ]);
        $duplicate = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth',
            'marketing_consent' => true, 'marketing_consent_at' => now()->subYears(2),
        ]);

        $merged = app(GuestMergeService::class)->merge($keep, $duplicate);

        $this->assertFalse((bool) $merged->marketing_consent);
    }

    public function test_merge_carries_the_send_history_over(): void
    {
        $setup = $this->createTenantSetup();
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Kern']);
        $duplicate = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Kern']);

        $campaign = MarketingCampaign::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'type' => 'winback', 'name' => 'Test', 'is_active' => true,
            'offset_days' => 180, 'min_visits' => 1,
            'subject' => 'Hallo', 'body' => 'Text',
        ]);
        MarketingSend::create([
            'tenant_id' => $setup['tenant']->id,
            'marketing_campaign_id' => $campaign->id,
            'guest_id' => $duplicate->id,
            'reference' => 'w-2026',
            'sent_at' => now(),
        ]);

        app(GuestMergeService::class)->merge($keep, $duplicate);

        $this->assertSame(1, MarketingSend::withoutGlobalScopes()->where('guest_id', $keep->id)->count());
    }

    public function test_merge_keeps_the_snapshot_of_an_earlier_merge(): void
    {
        $setup = $this->createTenantSetup();
        $a = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Berg']);
        $b = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Berg']);
        $c = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Berg']);

        $service = app(GuestMergeService::class);
        $service->merge($a, $b);              // Snapshot von B haengt an A
        $service->merge($c->fresh(), $a->fresh()); // A wird selbst zum Duplikat

        // Beide Snapshots muessen erhalten bleiben.
        $this->assertSame(2, GuestMergeLog::withoutGlobalScopes()->count());
    }

    // ── Anonymisierung ────────────────────────────────────────────────────

    public function test_anonymising_clears_the_event_booking_contact(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Lindner']);
        $event = Event::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinseminar', 'slug' => 'weinseminar',
            'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3),
            'capacity' => 20, 'status' => 'published',
        ]);
        EventBooking::create([
            'tenant_id' => $setup['tenant']->id, 'event_id' => $event->id, 'guest_id' => $guest->id,
            'code' => 'EV123456', 'ticket_count' => 2,
            'guest_name' => 'Jonas Lindner', 'guest_email' => 'j.lindner@example.test', 'guest_phone' => '0170123456',
        ]);
        $this->clearTenantContext();

        app(GuestPrivacyService::class)->anonymize($guest->fresh());

        $booking = EventBooking::withoutGlobalScopes()->firstOrFail();
        $this->assertStringNotContainsString('Lindner', $booking->guest_name);
        $this->assertNull($booking->guest_email);
        $this->assertNull($booking->guest_phone);
    }

    // ── Marketing ─────────────────────────────────────────────────────────

    public function test_a_cancelled_booking_is_not_a_visit(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'marketing_consent' => true,
            'visit_count' => 3,
            'birthday' => '1988-08-09',
        ]);
        Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
            'status' => ReservationStatus::CancelledByGuest,
        ]);

        $campaign = MarketingCampaign::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'type' => 'birthday', 'name' => 'Geburtstag', 'is_active' => true,
            'offset_days' => 3, 'min_visits' => 1,
            'subject' => 'Alles Gute', 'body' => 'Hallo {first_name}',
        ]);
        $this->clearTenantContext();

        $today = CarbonImmutable::parse('2026-08-06', $setup['location']->timezone)->startOfDay();
        $this->assertSame(0, app(MarketingCampaignService::class)->run($campaign, $today));
        Mail::assertNothingQueued();
    }

    // ── Salon-Umbuchung ───────────────────────────────────────────────────

    public function test_rescheduling_into_an_absence_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $tz = $setup['location']->timezone;

        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Anna', 'is_active' => true,
        ]);
        $service = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Balayage', 'duration_minutes' => 60, 'price_minor' => 9000, 'is_active' => true,
        ]);
        $staff->services()->attach($service->id);

        $start = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);
        $target = CarbonImmutable::now($tz)->addDays(2)->setTime(14, 0);

        StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $target->subHours(2)->utc(),
            'ends_at' => $target->addHours(5)->utc(),
            'reason' => 'Urlaub',
        ]);
        $this->clearTenantContext();

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 1,
            'start_local' => $start,
            'duration_minutes' => 60,
            'source' => 'manual',
            'guest_name' => 'Gast',
            'staff_member_id' => $staff->id,
            'skip_availability_check' => true,
        ]);
        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        app(ReservationLifecycleService::class)->reschedule($reservation->fresh(), $target);
    }

    // ── Umzug ─────────────────────────────────────────────────────────────

    public function test_export_and_import_carry_blocks_campaigns_and_rules(): void
    {
        $source = $this->createTenantSetup();
        $service = Service::create([
            'tenant_id' => $source['tenant']->id, 'location_id' => $source['location']->id,
            'name' => 'Balayage', 'duration_minutes' => 180, 'price_minor' => 18000, 'is_active' => true,
        ]);
        DepositRule::create([
            'tenant_id' => $source['tenant']->id, 'location_id' => $source['location']->id,
            'name' => 'Balayage-Anzahlung', 'type' => 'deposit', 'service_id' => $service->id,
            'room_id' => $source['room']->id,
            'amount_per_person_minor' => 0, 'flat_amount_minor' => 3000, 'currency' => 'EUR',
            'payment_deadline_minutes' => 60, 'is_active' => true,
        ]);
        MarketingCampaign::create([
            'tenant_id' => $source['tenant']->id, 'location_id' => $source['location']->id,
            'type' => 'rebooking', 'name' => 'Nachfassen', 'is_active' => true,
            'offset_days' => 42, 'min_visits' => 1, 'subject' => 'Hallo', 'body' => 'Text',
        ]);
        TableBlock::create([
            'tenant_id' => $source['tenant']->id, 'location_id' => $source['location']->id,
            'restaurant_table_id' => $source['tables'][0]->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Defekt',
        ]);
        $this->clearTenantContext();

        $payload = json_decode(json_encode(app(AccountExportService::class)->export($source['tenant'])), true);

        $this->assertNotEmpty($payload['table_blocks']);
        $this->assertNotEmpty($payload['marketing_campaigns']);

        // Zielbetrieb mit eigenem Benutzer, komplett leer.
        $target = $this->createTenantSetup([]);
        $this->clearTenantContext();
        app(AccountImportService::class)->import($target['tenant'], $payload);

        $importedRule = DepositRule::withoutGlobalScopes()->where('tenant_id', $target['tenant']->id)->firstOrFail();
        $importedService = Service::withoutGlobalScopes()->where('tenant_id', $target['tenant']->id)->firstOrFail();

        // Die Regel zeigt auf die Leistung des ZIELbetriebs, nicht auf die alte ID.
        $this->assertSame($importedService->id, $importedRule->service_id);
        $this->assertNotSame($service->id, $importedRule->service_id);
        $this->assertSame(1, MarketingCampaign::withoutGlobalScopes()->where('tenant_id', $target['tenant']->id)->count());
    }
}

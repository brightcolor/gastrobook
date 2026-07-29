<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\GuestNote;
use App\Models\Location;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\ReservationStatusHistory;
use App\Models\Tag;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Round-trip: what the export writes must come back in on import, with all
 * references intact — that is what makes an actual move possible.
 */
class AccountImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Build a source account with linked data and return its export payload. */
    private function sourceExport(): array
    {
        $setup = $this->createTenantSetup();

        $tag = Tag::create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'Fensterplatz',
            'color' => '#ff0000', 'scope' => 'reservation',
        ]);

        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier', 'email' => 'klara@example.test',
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'guest_id' => $guest->id, 'party_size' => 3,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Klara Meier', 'guest_email_snapshot' => 'klara@example.test',
        ]);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $reservation->tags()->attach($tag->id);

        $export = app(AccountExportService::class)->export($setup['tenant']);

        // Wipe the source so the import cannot accidentally "find" old rows.
        $setup['tenant']->forceDelete();

        return $export;
    }

    private function freshTenant(): Tenant
    {
        return Tenant::factory()->create(['plan_id' => Plan::factory()]);
    }

    public function test_round_trip_restores_data_with_intact_links(): void
    {
        $export = $this->sourceExport();
        $target = $this->freshTenant();

        $imported = app(AccountImportService::class)->import($target, $export);

        $this->assertSame(1, $imported['locations']);
        $this->assertSame(3, $imported['tables']);
        $this->assertSame(1, $imported['guests']);
        $this->assertSame(1, $imported['reservations']);

        $location = Location::withoutGlobalScopes()->where('tenant_id', $target->id)->first();
        $reservation = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->first();

        // Reservation is linked to the right location, guest, table and tag.
        $this->assertSame($location->id, $reservation->location_id);
        $this->assertSame('Klara Meier', $reservation->guest_name_snapshot);
        $this->assertSame('klara@example.test', $reservation->guest?->email);
        $this->assertSame('T2', $reservation->tables->first()->name);
        $this->assertSame('Fensterplatz', $reservation->tags->first()->name);

        // Opening hours came along, so the restored business is bookable.
        $this->assertSame(7, $location->openingHours()->count());
    }

    public function test_new_codes_and_tokens_are_generated(): void
    {
        $export = $this->sourceExport();
        $target = $this->freshTenant();

        app(AccountImportService::class)->import($target, $export);

        $reservation = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->first();

        $this->assertNotEmpty($reservation->code);
        $this->assertNotEmpty($reservation->manage_token);
        $this->assertSame(48, strlen($reservation->manage_token));
    }

    public function test_import_does_not_reuse_source_ids(): void
    {
        $export = $this->sourceExport();
        $target = $this->freshTenant();

        // Occupy an id range in the target so a naive import would collide.
        $other = $this->freshTenant();
        Location::factory()->count(3)->create(['tenant_id' => $other->id]);

        $imported = app(AccountImportService::class)->import($target, $export);

        $this->assertSame(1, $imported['locations']);
        // 1 imported + 3 filler (the source account was deleted before the import)
        $this->assertDatabaseCount('locations', 4);
    }

    /**
     * The target business is rarely empty — it already has its own tags and
     * e-mail templates. Those share a unique key with the imported ones, so the
     * import has to reuse them instead of running into a duplicate-key error.
     */
    public function test_import_into_a_business_that_already_has_tags_reuses_them(): void
    {
        $export = $this->sourceExport();

        $setup = $this->createTenantSetup([]);
        $target = $setup['tenant'];

        // Same natural key (name + scope) as the exported tag.
        Tag::create([
            'tenant_id' => $target->id, 'name' => 'Fensterplatz',
            'color' => '#00ff00', 'scope' => 'reservation',
        ]);

        $imported = app(AccountImportService::class)->import($target, $export);

        // Reused, not duplicated.
        $this->assertArrayNotHasKey('tags', $imported);
        $this->assertSame(
            1,
            Tag::withoutGlobalScopes()->where('tenant_id', $target->id)->where('name', 'Fensterplatz')->count()
        );

        // The imported reservation still carries the tag — via the existing one.
        $reservation = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->first();
        $this->assertSame('Fensterplatz', $reservation->tags->first()->name);
        $this->assertSame('#00ff00', $reservation->tags->first()->color);
    }

    /**
     * A move has to carry the paper trail too: what was paid, what was noted,
     * how a booking got to its status. Open refunds are the exception — see
     * AccountImportService::refunds().
     */
    public function test_payments_notes_history_and_feedback_come_along(): void
    {
        $setup = $this->createTenantSetup();
        $tenantId = $setup['tenant']->id;

        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'first_name' => 'Klara', 'last_name' => 'Meier',
        ]);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $tenantId, 'location_id' => $setup['location']->id,
            'guest_id' => $guest->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Klara Meier',
        ]);

        GuestNote::create(['tenant_id' => $tenantId, 'guest_id' => $guest->id, 'body' => 'Stammgast, mag Fensterplatz.']);
        ReservationNote::create(['tenant_id' => $tenantId, 'reservation_id' => $reservation->id, 'body' => 'Ruft vorher an.']);
        ReservationStatusHistory::create([
            'tenant_id' => $tenantId, 'reservation_id' => $reservation->id,
            'from_status' => 'pending', 'to_status' => 'confirmed', 'actor' => 'staff',
        ]);
        $payment = PaymentIntent::create([
            'tenant_id' => $tenantId, 'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'type' => 'deposit', 'amount_minor' => 2000,
            'currency' => 'EUR', 'status' => 'paid',
        ]);
        Refund::create([
            'tenant_id' => $tenantId, 'reservation_id' => $reservation->id,
            'payment_intent_id' => $payment->id, 'provider' => 'stripe',
            'amount_minor' => 2000, 'currency' => 'EUR', 'status' => 'approved', 'source' => 'auto',
        ]);

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $setup['tenant']->forceDelete();

        $target = $this->freshTenant();
        $imported = app(AccountImportService::class)->import($target, $export);

        $this->assertSame(1, $imported['guest_notes']);
        $this->assertSame(1, $imported['reservation_notes']);
        $this->assertSame(1, $imported['reservation_status_history']);
        $this->assertSame(1, $imported['payments']);
        $this->assertSame(1, $imported['refunds']);

        $newReservation = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->first();

        // Everything hangs off the *new* reservation id, not the old one.
        $newPayment = PaymentIntent::withoutGlobalScopes()->where('tenant_id', $target->id)->first();
        $this->assertSame($newReservation->id, $newPayment->reservation_id);
        $this->assertSame(2000, $newPayment->amount_minor);
        $this->assertSame('paid', $newPayment->status);

        $this->assertSame(
            $newReservation->id,
            ReservationNote::withoutGlobalScopes()->where('tenant_id', $target->id)->first()->reservation_id
        );
        $this->assertSame(
            'confirmed',
            ReservationStatusHistory::withoutGlobalScopes()->where('tenant_id', $target->id)->first()->to_status
        );

        // The open refund is closed instead of being retried against a provider
        // that never saw the original payment.
        $newRefund = Refund::withoutGlobalScopes()->where('tenant_id', $target->id)->first();
        $this->assertSame('failed', $newRefund->status);
        $this->assertNull($newRefund->scheduled_for);
        $this->assertStringContainsString('alten System', (string) $newRefund->error);
        $this->assertSame($newPayment->id, $newRefund->payment_intent_id);
    }

    public function test_broken_file_is_rejected_and_changes_nothing(): void
    {
        $target = $this->freshTenant();
        $before = DB::table('locations')->count();

        try {
            app(AccountImportService::class)->import($target, ['format' => 'etwas-anderes']);
            $this->fail('Eine fremde Datei hätte abgelehnt werden müssen.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kein Swayy-Account-Export', $e->getMessage());
        }

        $this->assertSame($before, DB::table('locations')->count());
    }

    public function test_preview_reports_what_would_be_imported(): void
    {
        $export = $this->sourceExport();

        $preview = app(AccountImportService::class)->preview($export);

        $this->assertSame(1, $preview['locations']);
        $this->assertSame(3, $preview['tables']);
        $this->assertSame(1, $preview['reservations']);
    }

    public function test_upload_endpoint_is_owner_only(): void
    {
        Storage::fake();
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $file = UploadedFile::fake()->createWithContent('export.json', json_encode([
            'format' => 'swayy-account-export', 'format_version' => 1,
            'locations' => [['id' => 1, 'name' => 'X', 'slug' => 'x', 'timezone' => 'Europe/Berlin']],
        ]));

        $this->actingAs($manager)
            ->post('/admin/account/import', ['file' => $file, 'confirm' => '1'])
            ->assertForbidden();
    }

    public function test_owner_can_upload_and_gets_a_summary(): void
    {
        Storage::fake();
        $export = $this->sourceExport();

        $setup = $this->createTenantSetup([]); // target business, no tables
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $file = UploadedFile::fake()->createWithContent('export.json', json_encode($export));

        $this->actingAs($owner)
            ->post('/admin/account/import', ['file' => $file, 'confirm' => '1'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $setup['tenant']->id,
            'action' => 'account.imported',
        ]);
        $this->assertSame(
            1,
            Reservation::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count()
        );
    }
}

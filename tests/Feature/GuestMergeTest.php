<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\GuestMergeLog;
use App\Models\GuestNote;
use App\Models\Reservation;
use App\Models\Tag;
use App\Services\GuestMergeService;
use App\Services\GuestPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Duplicate guest profiles. Dedupe on creation only catches what comes in
 * through the same channel – a guest who books once online and once by phone
 * still lands twice in the CRM. `guest_merge_logs` existed from the start; the
 * merge itself did not.
 */
class GuestMergeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_history_notes_consents_and_counters_move_over(): void
    {
        $setup = $this->createTenantSetup();
        $tenantId = $setup['tenant']->id;

        $keep = Guest::factory()->create([
            'tenant_id' => $tenantId, 'last_name' => 'Schmidt', 'first_name' => 'Anna',
            'email' => 'anna@example.test', 'phone' => null,
            'visit_count' => 3, 'no_show_count' => 1, 'avg_party_size' => 2.0,
        ]);
        $duplicate = Guest::factory()->create([
            'tenant_id' => $tenantId, 'last_name' => 'Schmidt', 'first_name' => 'Anna',
            'email' => 'anna+privat@example.test', 'phone' => '0170 1234567',
            'visit_count' => 1, 'no_show_count' => 2, 'avg_party_size' => 6.0,
            'is_vip' => true,
        ]);

        $reservation = Reservation::factory()->create([
            'tenant_id' => $tenantId, 'location_id' => $setup['location']->id, 'guest_id' => $duplicate->id,
        ]);
        GuestNote::create(['tenant_id' => $tenantId, 'guest_id' => $duplicate->id, 'body' => 'Sitzt gern am Fenster']);
        GuestConsent::create([
            'tenant_id' => $tenantId, 'guest_id' => $duplicate->id,
            'type' => 'marketing', 'granted' => true, 'channel' => 'online', 'recorded_at' => now(),
        ]);

        $merged = app(GuestMergeService::class)->merge($keep, $duplicate);

        $this->assertSame($keep->id, $reservation->fresh()->guest_id);
        $this->assertSame(1, GuestNote::withoutGlobalScopes()->where('guest_id', $keep->id)->count());
        $this->assertSame(1, GuestConsent::withoutGlobalScopes()->where('guest_id', $keep->id)->count());

        // Counters add up, the empty phone number is filled in, VIP wins.
        $this->assertSame(4, $merged->visit_count);
        $this->assertSame(3, $merged->no_show_count);
        $this->assertSame('0170 1234567', $merged->phone);
        $this->assertTrue((bool) $merged->is_vip);
        // Weighted: (3 × 2 + 1 × 6) / 4 = 3
        $this->assertSame(3.0, (float) $merged->avg_party_size);

        // The duplicate is gone for good, but stays traceable.
        // withoutGlobalScopes also drops the soft-delete filter – nothing left at all.
        $this->assertNull(Guest::withoutGlobalScopes()->find($duplicate->id));
        $log = GuestMergeLog::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($keep->id, $log->kept_guest_id);
        $this->assertSame($duplicate->id, $log->merged_guest_id);
        $this->assertSame('anna+privat@example.test', $log->merged_snapshot['email']);
    }

    public function test_tags_are_combined(): void
    {
        $setup = $this->createTenantSetup();
        $tenantId = $setup['tenant']->id;
        $vip = Tag::create(['tenant_id' => $tenantId, 'name' => 'Weinliebhaber', 'color' => '#111111', 'scope' => 'guest']);
        $allergy = Tag::create(['tenant_id' => $tenantId, 'name' => 'Nussallergie', 'color' => '#222222', 'scope' => 'guest']);

        $keep = Guest::factory()->create(['tenant_id' => $tenantId, 'last_name' => 'Berg']);
        $duplicate = Guest::factory()->create(['tenant_id' => $tenantId, 'last_name' => 'Berg']);
        $keep->tags()->attach($vip->id);
        $duplicate->tags()->attach($allergy->id);

        $merged = app(GuestMergeService::class)->merge($keep, $duplicate);

        $this->assertEqualsCanonicalizing(
            [$vip->id, $allergy->id],
            $merged->tags()->pluck('tags.id')->all()
        );
    }

    /**
     * The snapshot holds the duplicate's plain data. Erasure of the surviving
     * profile has to take it along, otherwise a readable copy would remain.
     */
    public function test_anonymising_removes_the_merge_snapshot(): void
    {
        $setup = $this->createTenantSetup();
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Kern']);
        $duplicate = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Kern']);

        app(GuestMergeService::class)->merge($keep, $duplicate);
        $this->assertSame(1, GuestMergeLog::withoutGlobalScopes()->count());

        app(GuestPrivacyService::class)->anonymize($keep->fresh());

        $this->assertSame(0, GuestMergeLog::withoutGlobalScopes()->count());
    }

    public function test_a_guest_cannot_be_merged_with_itself(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id]);

        $this->expectException(ValidationException::class);
        app(GuestMergeService::class)->merge($guest, $guest);
    }

    public function test_anonymised_profiles_are_refused(): void
    {
        $setup = $this->createTenantSetup();
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $duplicate = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'anonymized' => true]);

        $this->expectException(ValidationException::class);
        app(GuestMergeService::class)->merge($keep, $duplicate);
    }

    public function test_profiles_of_two_businesses_never_mix(): void
    {
        $one = $this->createTenantSetup();
        $two = $this->createTenantSetup();
        $keep = Guest::factory()->create(['tenant_id' => $one['tenant']->id]);
        $foreign = Guest::factory()->create(['tenant_id' => $two['tenant']->id]);

        $this->expectException(ValidationException::class);
        app(GuestMergeService::class)->merge($keep, $foreign);
    }

    public function test_duplicates_are_suggested_on_the_guest_page(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $keep = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'last_name' => 'Vogel', 'email' => 'vogel@example.test',
        ]);
        Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'last_name' => 'Vogel', 'email' => 'VOGEL@example.test',
        ]);
        // Same tenant, nothing in common – must not be suggested.
        Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Fuchs']);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/guests/'.$keep->id)
            ->assertOk()
            ->assertSee('Mögliche Dublette')
            ->assertDontSee('Fuchs');
    }

    public function test_the_merge_can_be_triggered_from_the_admin(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth']);
        $duplicate = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth']);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/guests/'.$keep->id.'/merge', ['merge_guest_id' => $duplicate->id])
            ->assertRedirect();

        $this->assertSame(1, Guest::withoutGlobalScopes()->count());
    }

    public function test_service_staff_may_not_merge_profiles(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'host');
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth']);
        $duplicate = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'last_name' => 'Roth']);
        $this->clearTenantContext();

        $this->actingAs($staff)
            ->post('/admin/guests/'.$keep->id.'/merge', ['merge_guest_id' => $duplicate->id])
            ->assertForbidden();

        $this->assertSame(2, Guest::withoutGlobalScopes()->count());
    }

    public function test_a_guest_of_another_business_cannot_be_pulled_in(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $keep = Guest::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $foreign = Guest::factory()->create(['tenant_id' => $other['tenant']->id]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/guests/'.$keep->id.'/merge', ['merge_guest_id' => $foreign->id])
            ->assertSessionHasErrors('merge_guest_id');

        $this->assertSame(2, Guest::withoutGlobalScopes()->count());
    }
}

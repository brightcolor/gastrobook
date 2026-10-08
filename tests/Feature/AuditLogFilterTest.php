<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLogFilterTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function log(int $tenantId, string $action, ?string $entityType = null, ?int $entityId = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => now(),
        ]);
    }

    public function test_the_entity_filter_shows_only_one_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed', Reservation::class, 41);
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=reservation&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'))
            ->assertDontSee(AuditLog::labelFor('reservation.status_changed'))
            ->assertSee('Reservierung Nr. 42');
    }

    public function test_an_unknown_entity_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=App%5CModels%5CUser&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'));
    }

    public function test_the_area_filter_uses_the_action_prefix(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'table.deleted');
        $this->log($setup['tenant']->id, 'table_block.created');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?area=table')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('table.deleted'))
            ->assertDontSee(AuditLog::labelFor('table_block.created'))
            ->assertSee('<option value="table" selected', false);
    }

    public function test_page_size_comes_from_the_settings(): void
    {
        config(['swayy.audit.per_page' => 10]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (range(1, 12) as $i) {
            $this->log($setup['tenant']->id, 'guest.updated', null, $i);
        }
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/audit')->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'Gast geändert'));
    }

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(100, config('swayy.audit.per_page'));
        $this->assertSame(Reservation::class, config('swayy.audit.entities.reservation'));
    }

    public function test_without_entries_the_page_says_what_will_appear(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Noch keine Einträge');

        $this->actingAs($owner)->get('/admin/audit?area=guest')
            ->assertOk()
            ->assertSee('Keine passenden Einträge')
            ->assertSee('Alle Einträge zeigen');
    }

    public function test_reservation_detail_links_to_its_history_for_roles_with_audit_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(2)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $this->clearTenantContext();
        $link = e(route('admin.audit.index', ['entity' => 'reservation', 'entity_id' => $reservation->id]));

        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee($link, false)
            ->assertSee('Alle Änderungen im Protokoll');

        $this->actingAs($host)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertDontSee('Alle Änderungen im Protokoll');
    }
}

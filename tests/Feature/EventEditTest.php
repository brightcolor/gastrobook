<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventEditTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function makeEvent(array $setup, array $overrides = []): Event
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDays(7)->setTime(19, 0);

        return Event::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'title' => 'Weinprobe',
            'slug' => 'weinprobe',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHours(3)->utc(),
            'capacity' => 20,
            'price_minor' => 5000,
            'currency' => 'EUR',
            'is_public' => true,
            'status' => 'published',
        ], $overrides));
    }

    public function test_admin_can_edit_event_details(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $event = $this->makeEvent($setup);
        $originalSlug = $event->slug;
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/events/{$event->id}", [
            'title' => 'Große Weinprobe',
            'date' => CarbonImmutable::now('Europe/Berlin')->addDays(10)->format('Y-m-d'),
            'start_time' => '18:30',
            'end_time' => '22:00',
            'capacity' => 30,
            'price' => '59.00',
        ])->assertRedirect();

        $fresh = $event->fresh();
        $this->assertSame('Große Weinprobe', $fresh->title);
        $this->assertSame(30, $fresh->capacity);
        $this->assertSame(5900, $fresh->price_minor);
        $this->assertSame($originalSlug, $fresh->slug, 'Slug stays stable so public links keep working.');
    }

    public function test_capacity_cannot_drop_below_sold_tickets(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $event = $this->makeEvent($setup, ['capacity' => 20]);
        EventBooking::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'event_id' => $event->id,
            'ticket_count' => 8,
            'guest_name' => 'Gruppe',
            'status' => 'confirmed',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/events/{$event->id}", [
            'title' => 'Weinprobe',
            'date' => CarbonImmutable::now('Europe/Berlin')->addDays(7)->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '22:00',
            'capacity' => 5, // below the 8 already sold
        ])->assertSessionHasErrors('capacity');

        $this->assertSame(20, $event->fresh()->capacity);
    }

    public function test_staff_cannot_edit_events(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $staff = $this->createMember($setup['tenant'], 'staff');
        $event = $this->makeEvent($setup);
        $this->clearTenantContext();

        $this->actingAs($staff)->put("/admin/events/{$event->id}", [
            'title' => 'Hack', 'date' => '2026-08-01', 'start_time' => '19:00',
            'end_time' => '22:00', 'capacity' => 10,
        ])->assertForbidden();
    }
}

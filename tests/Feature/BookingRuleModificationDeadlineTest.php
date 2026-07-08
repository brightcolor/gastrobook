<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingRuleModificationDeadlineTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 30,
            'default_duration_minutes' => 120,
            'buffer_minutes' => 0,
            'min_lead_minutes' => 0,
            'max_advance_days' => 90,
            'min_party_online' => 1,
            'max_party_online' => 10,
            'booking_confirmation_mode' => 'auto',
            'capacity_mode' => 'table',
            'cancellation_deadline_minutes' => 120,
            'modification_deadline_minutes' => 120,
            'reminder_hours_before' => 24,
            'refund_mode' => 'off',
            'refund_percent' => 0,
            'refund_processing' => 'immediate',
            'guest_address' => 'Sie',
            'feedback_hours_after' => 18,
            'feedback_redirect_min_score' => 4,
        ], $overrides);
    }

    public function test_modification_deadline_is_editable(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/settings/booking-rules', $this->payload(['modification_deadline_minutes' => 360]))
            ->assertRedirect();

        $this->assertSame(360, (int) $setup['location']->settings()->first()->modification_deadline_minutes);
    }

    public function test_modification_deadline_is_required(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $payload = $this->payload();
        unset($payload['modification_deadline_minutes']);

        $this->actingAs($admin)
            ->put('/admin/settings/booking-rules', $payload)
            ->assertSessionHasErrors('modification_deadline_minutes');
    }
}

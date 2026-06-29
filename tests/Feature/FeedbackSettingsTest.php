<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FeedbackSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Full valid booking-rules payload + the given overrides. */
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
            'reminder_hours_before' => 24,
            'refund_mode' => 'off',
            'refund_percent' => 0,
            'refund_processing' => 'immediate',
            'guest_address' => 'du',
            'feedback_hours_after' => 18,
            'feedback_redirect_min_score' => 4,
        ], $overrides);
    }

    public function test_feedback_settings_can_be_saved(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/booking-rules', $this->payload([
            'feedback_enabled' => '1',
            'feedback_hours_after' => 24,
            'feedback_redirect_min_score' => 5,
            'feedback_external_url' => 'https://g.page/r/abc/review',
        ]))->assertRedirect();

        $settings = $setup['location']->settings()->first();
        $this->assertTrue((bool) $settings->feedback_enabled);
        $this->assertSame(24, (int) $settings->feedback_hours_after);
        $this->assertSame(5, (int) $settings->feedback_redirect_min_score);
        $this->assertSame('https://g.page/r/abc/review', $settings->feedback_external_url);
    }

    public function test_external_url_must_be_valid_https(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/booking-rules', $this->payload([
            'feedback_external_url' => 'not-a-url',
        ]))->assertSessionHasErrors('feedback_external_url');
    }
}

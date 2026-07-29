<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Table time per party size: a six-person table is blocked longer than a table
 * for two. The rules already drove durationFor(); this covers the editor that
 * finally lets a business set them.
 */
class DurationRulesTest extends TestCase
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

    private function save(array $rules)
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)
            ->put('/admin/settings/booking-rules', $this->payload(['duration_rules' => $rules]));

        return [$response, $setup['location']];
    }

    public function test_rules_are_saved_and_drive_the_booking_duration(): void
    {
        [$response, $location] = $this->save([
            ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ['min_party' => 3, 'max_party' => 5, 'duration' => 105],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $settings = $location->settings()->first();
        $this->assertCount(2, $settings->duration_rules);

        // The whole point: the duration now depends on the party size.
        $this->assertSame(75, $settings->durationFor(2));
        $this->assertSame(105, $settings->durationFor(4));
        // Anything without a rule keeps the default.
        $this->assertSame(120, $settings->durationFor(8));
    }

    public function test_rules_are_sorted_by_party_size(): void
    {
        // durationFor() returns the first match, so order in storage matters.
        [, $location] = $this->save([
            ['min_party' => 6, 'max_party' => 20, 'duration' => 150],
            ['min_party' => 1, 'max_party' => 5, 'duration' => 90],
        ]);

        $rules = $location->settings()->first()->duration_rules;

        $this->assertSame(1, $rules[0]['min_party']);
        $this->assertSame(6, $rules[1]['min_party']);
    }

    public function test_overlapping_party_sizes_are_rejected(): void
    {
        [$response, $location] = $this->save([
            ['min_party' => 1, 'max_party' => 4, 'duration' => 90],
            ['min_party' => 4, 'max_party' => 8, 'duration' => 150],
        ]);

        $response->assertSessionHasErrors('duration_rules');
        // Nothing was written, so the old behaviour stays intact.
        $this->assertEmpty($location->settings()->first()?->duration_rules ?? []);
    }

    public function test_reversed_range_is_rejected(): void
    {
        [$response] = $this->save([
            ['min_party' => 6, 'max_party' => 2, 'duration' => 90],
        ]);

        $response->assertSessionHasErrors('duration_rules');
    }

    public function test_half_filled_row_is_rejected(): void
    {
        [$response] = $this->save([
            ['min_party' => 2, 'max_party' => '', 'duration' => 90],
        ]);

        $response->assertSessionHasErrors('duration_rules');
    }

    public function test_empty_rows_are_dropped_and_rules_can_be_cleared(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        LocationSettings::withoutGlobalScopes()
            ->where('location_id', $setup['location']->id)
            ->update(['duration_rules' => json_encode([['min_party' => 1, 'max_party' => 4, 'duration' => 60]])]);

        // Submitting the form with only blank rows removes them again.
        $this->actingAs($admin)
            ->put('/admin/settings/booking-rules', $this->payload([
                'duration_rules' => [['min_party' => '', 'max_party' => '', 'duration' => '']],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = $setup['location']->settings()->first();
        $this->assertSame([], $settings->duration_rules);
        $this->assertSame(120, $settings->durationFor(3));
    }

    public function test_editor_is_visible_in_the_booking_rules_tab(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Tischzeit je Gruppengröße')
            ->assertSee('+ Tischzeit hinzufügen');
    }
}

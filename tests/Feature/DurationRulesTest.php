<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Table time per party size: a six-person table is blocked longer than a table
 * for two. The rules already drove durationFor(); this covers the editor on
 * the page Kapazität & Dauer that lets a business set them.
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
            'capacity_mode' => 'table',
            'max_covers_per_slot' => '',
        ], $overrides);
    }

    private function save(array $rules)
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)
            ->put('/admin/settings/capacity', $this->payload(['duration_rules' => $rules]));

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

        // Der Fehler steht an der Zeile, die sich überschneidet.
        $response->assertSessionHasErrors('duration_rules.1.min_party');
        // Nothing was written, so the old behaviour stays intact.
        $this->assertEmpty($location->settings()->first()?->duration_rules ?? []);
    }

    public function test_reversed_range_is_rejected(): void
    {
        [$response] = $this->save([
            ['min_party' => 6, 'max_party' => 2, 'duration' => 90],
        ]);

        $response->assertSessionHasErrors('duration_rules.0.max_party');
    }

    public function test_half_filled_row_is_rejected(): void
    {
        [$response] = $this->save([
            ['min_party' => 2, 'max_party' => '', 'duration' => 90],
        ]);

        $response->assertSessionHasErrors('duration_rules.0.max_party');
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
            ->put('/admin/settings/capacity', $this->payload([
                'duration_rules' => [['min_party' => '', 'max_party' => '', 'duration' => '']],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = $setup['location']->settings()->first();
        $this->assertSame([], $settings->duration_rules);
        $this->assertSame(120, $settings->durationFor(3));
    }

    public function test_the_limits_follow_the_settings(): void
    {
        config(['swayy.booking.rule_duration_max' => 90, 'swayy.booking.duration_rules_max' => 2]);

        [$response] = $this->save([['min_party' => 1, 'max_party' => 2, 'duration' => 120]]);
        $response->assertSessionHasErrors(['duration_rules.0.duration' => 'Eine Tischzeit darf höchstens 90 Minuten betragen.']);

        [$response] = $this->save([
            ['min_party' => 1, 'max_party' => 2, 'duration' => 60],
            ['min_party' => 3, 'max_party' => 4, 'duration' => 60],
            ['min_party' => 5, 'max_party' => 6, 'duration' => 60],
        ]);
        $response->assertSessionHasErrors(['duration_rules' => 'Die Zahl der Tischzeiten ist auf 2 begrenzt. Bitte Gruppengrößen zusammenfassen.']);
    }

    public function test_the_largest_values_the_settings_allow_fit_their_columns(): void
    {
        // Unter PostgreSQL (tests-pgsql) lehnt eine zu kleine Spalte den Wert mit einem Fehler ab.
        config([
            'swayy.booking.slot_intervals' => [240],
            'swayy.booking.duration_max' => 1440,
            'swayy.booking.buffer_max' => 480,
            'swayy.booking.covers_max' => 32767,
            'swayy.booking.lead_max_minutes' => 32767,
            'swayy.booking.advance_max_days' => 1825,
            'swayy.booking.deadline_max_minutes' => 32767,
            'swayy.booking.party_online_max' => 100,
        ]);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/settings/capacity', $this->payload([
                'slot_interval_minutes' => 240,
                'default_duration_minutes' => 1440,
                'buffer_minutes' => 480,
                'capacity_mode' => 'person',
                'max_covers_per_slot' => 32767,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put('/admin/settings/rules', [
                'min_lead_minutes' => 32767,
                'max_advance_days' => 1825,
                'min_party_online' => 100,
                'max_party_online' => 100,
                'large_group_email' => '',
                'cancellation_deadline_minutes' => 32767,
                'modification_deadline_minutes' => 32767,
                'booking_confirmation_mode' => 'auto',
                'require_email_confirmation' => '0',
                'waitlist_enabled' => '1',
                'walkins_enabled' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = $setup['location']->settings()->first();
        $this->assertSame(
            [240, 1440, 480, 32767, 32767, 1825, 100, 100, 32767, 32767],
            [
                $settings->slot_interval_minutes, $settings->default_duration_minutes, $settings->buffer_minutes,
                $settings->max_covers_per_slot, $settings->min_lead_minutes, $settings->max_advance_days,
                $settings->min_party_online, $settings->max_party_online,
                $settings->cancellation_deadline_minutes, $settings->modification_deadline_minutes,
            ],
        );
    }

    public function test_editor_is_visible_on_the_capacity_page(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('Tischzeiten je Gruppengröße')
            ->assertSee('Tischzeit hinzufügen');
    }
}

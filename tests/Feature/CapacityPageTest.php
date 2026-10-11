<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CapacityPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function restaurant(array $override = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 15,
            'default_duration_minutes' => 105,
            'buffer_minutes' => 10,
            'capacity_mode' => 'hybrid',
            'max_covers_per_slot' => 60,
            'duration_rules' => [
                'n1' => ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
                0 => ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ],
        ], $override);
    }

    public function test_owner_sees_the_page_with_its_sections(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'duration_rules' => json_encode([['min_party' => 1, 'max_party' => 2, 'duration' => 75]]),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('id="takt"', false)
            ->assertSee('id="tischzeiten"', false)
            ->assertSee('id="kapazitaet"', false)
            ->assertSee('name="duration_rules[0][min_party]"', false)
            ->assertSee('data-rows-template', false)
            ->assertSee('name="duration_rules[__INDEX__][duration]"', false)
            ->assertSee('<option value="15"', false)
            ->assertSee('Takt und Dauer')
            ->assertSee('etwa zum Abräumen und Eindecken.')
            ->assertDontSee('diese Tischzeit');
        $this->assertMatchesRegularExpression('/data-nav-key="capacity"[^>]*aria-current="page"/', (string) $response->getContent());
        // Die gespeicherte Zeile und die Vorlage für neue Zeilen tragen dieselbe Gruppe.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'role="group" aria-label="Tischzeit"'));
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/capacity')->assertForbidden();
        $this->actingAs($host)->putJson('/admin/settings/capacity', $this->restaurant())->assertForbidden();
    }

    public function test_saving_writes_only_capacity_fields(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_lead_minutes' => 45,
            'cancellation_deadline_minutes' => 300,
            'reminder_enabled' => false,
            'refund_mode' => 'manual',
            'confetti_on_booking' => false,
            'public_floorplan_enabled' => true,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant())
            ->assertOk()
            ->assertExactJson(['message' => 'Kapazität und Dauer gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame(15, $settings->slot_interval_minutes);
        $this->assertSame(105, $settings->default_duration_minutes);
        $this->assertSame(10, $settings->buffer_minutes);
        $this->assertSame('hybrid', $settings->capacity_mode);
        $this->assertSame(60, $settings->max_covers_per_slot);
        $this->assertSame([
            ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
        ], $settings->duration_rules);
        $this->assertSame(45, $settings->min_lead_minutes);
        $this->assertSame(300, $settings->cancellation_deadline_minutes);
        $this->assertFalse($settings->reminder_enabled);
        $this->assertSame('manual', $settings->refund_mode);
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue($settings->public_floorplan_enabled);
        $this->assertTrue(AuditLog::where('action', 'location.capacity_updated')->exists());
    }

    public function test_saving_the_same_new_rows_twice_leaves_exactly_the_submitted_rules(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // rows.js behält die Schlüssel neuer Zeilen (n1, n2 …) nach dem Speichern;
        // ein zweites Speichern schickt dieselben Schlüssel noch einmal.
        $payload = $this->restaurant([
            'duration_rules' => [
                'n1' => ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
                'n2' => ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
            ],
        ]);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)->assertOk();
        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)->assertOk();

        $this->assertSame([
            ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
        ], $setup['location']->settings()->first()->duration_rules);
    }

    public function test_removing_every_row_clears_the_rules(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'duration_rules' => json_encode([['min_party' => 1, 'max_party' => 2, 'duration' => 75]]),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->restaurant();
        unset($payload['duration_rules']);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)->assertOk();

        $this->assertSame([], $setup['location']->settings()->first()->duration_rules);
    }

    public function test_a_missing_field_is_named(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->restaurant();
        unset($payload['capacity_mode']);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.capacity_mode.0', 'Bei „Zählweise“ fehlt ein Wert. Bitte einen Wert eintragen oder wählen; steht das Feld nicht auf der Seite, die Seite neu laden.');
    }

    public function test_an_unknown_choice_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant(['capacity_mode' => 'beides', 'slot_interval_minutes' => 7]))
            ->assertStatus(422)
            ->assertJsonPath('errors.capacity_mode.0', 'Der Wert bei „Zählweise“ steht nicht zur Auswahl. Bitte einen Wert aus der Liste wählen oder die Seite neu laden.')
            ->assertJsonPath('errors.slot_interval_minutes.0', 'Der Wert bei „Takt der Uhrzeiten“ steht nicht zur Auswahl. Bitte einen Wert aus der Liste wählen oder die Seite neu laden.');
    }

    public function test_a_value_that_is_no_number_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant([
            'buffer_minutes' => 'viel',
            'duration_rules' => ['n1' => ['min_party' => 'zwei', 'max_party' => 4, 'duration' => 90]],
        ]))->assertStatus(422);

        $this->assertSame('Bei „Puffer nach jeder Buchung“ bitte eine ganze Zahl eintragen.', $response->json('errors.buffer_minutes.0'));
        $this->assertSame('Bei „Ab Personen“ bitte eine ganze Zahl eintragen.', $response->json('errors')['duration_rules.n1.min_party'][0] ?? null);
    }

    public function test_the_salon_switch_names_the_next_step_when_it_is_missing_or_unclear(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/capacity', ['slot_interval_minutes' => 30, 'buffer_minutes' => 15])
            ->assertStatus(422)
            ->assertJsonPath('errors.gap_optimization_enabled.0', 'Bei „Lückenoptimierung“ fehlt ein Wert. Bitte einen Wert eintragen oder wählen; steht das Feld nicht auf der Seite, die Seite neu laden.');

        $this->actingAs($owner)->putJson('/admin/settings/capacity', ['slot_interval_minutes' => 30, 'buffer_minutes' => 15, 'gap_optimization_enabled' => 'vielleicht'])
            ->assertStatus(422)
            ->assertJsonPath('errors.gap_optimization_enabled.0', 'Bitte „Lückenoptimierung“ ein- oder ausschalten.');
    }

    public function test_an_overlapping_rule_points_to_its_row(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant([
            'duration_rules' => [
                0 => ['min_party' => 1, 'max_party' => 4, 'duration' => 90],
                'n1' => ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
            ],
        ]))->assertStatus(422);

        $this->assertSame(
            'Die Gruppengrößen überschneiden sich: „1–4“ und „3–6“. Jede Gruppengröße darf nur in einer Regel vorkommen.',
            $response->json('errors')['duration_rules.n1.min_party'][0] ?? null,
        );
    }

    public function test_a_rule_above_the_group_limit_names_the_limit_and_the_next_step(): void
    {
        config(['swayy.booking.rule_party_max' => 60]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant([
            'duration_rules' => [0 => ['min_party' => 20, 'max_party' => 80, 'duration' => 180]],
        ]))->assertStatus(422);

        $this->assertSame(
            'Die Buchungswege nehmen Gruppen bis 60 Personen an. Bitte bei „Bis Personen“ höchstens 60 eintragen oder die Zeile entfernen.',
            $response->json('errors')['duration_rules.0.max_party'][0] ?? null,
        );
    }

    public function test_salons_save_interval_buffer_and_gap_optimisation_only(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update([
            'default_duration_minutes' => 120,
            'capacity_mode' => 'table',
            'duration_rules' => json_encode([['min_party' => 1, 'max_party' => 2, 'duration' => 75]]),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('name="gap_optimization_enabled"', false)
            ->assertDontSee('name="capacity_mode"', false)
            ->assertDontSee('name="default_duration_minutes"', false)
            ->assertSee('Takt und Puffer')
            ->assertDontSee('Takt und Dauer')
            ->assertSee('etwa zum Aufräumen und Vorbereiten.')
            ->assertDontSee('Abräumen und Eindecken');

        $this->actingAs($owner)->putJson('/admin/settings/capacity', [
            'slot_interval_minutes' => 30,
            'buffer_minutes' => 15,
            'gap_optimization_enabled' => '1',
        ])->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertTrue($settings->gap_optimization_enabled);
        $this->assertSame(15, $settings->buffer_minutes);
        $this->assertSame(120, $settings->default_duration_minutes);
        $this->assertSame('table', $settings->capacity_mode);
        $this->assertCount(1, $settings->duration_rules);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.booking.buffer_max' => 30]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk()->assertSee('max="30"', false);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant(['buffer_minutes' => 45]))
            ->assertStatus(422)
            ->assertJsonPath('errors.buffer_minutes.0', 'Puffer nach jeder Buchung darf höchstens 30 sein.');
    }

    public function test_the_number_of_rules_follows_the_settings(): void
    {
        config(['swayy.booking.duration_rules_max' => 2]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('data-rows-max="2"', false)
            ->assertSee('bis zu 2 Regeln');

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant([
            'duration_rules' => [
                'n1' => ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
                'n2' => ['min_party' => 3, 'max_party' => 4, 'duration' => 90],
                'n3' => ['min_party' => 5, 'max_party' => 6, 'duration' => 120],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.duration_rules.0', 'Die Zahl der Tischzeiten ist auf 2 begrenzt. Bitte Gruppengrößen zusammenfassen.');
    }

    public function test_a_rejected_form_without_javascript_keeps_the_input(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/capacity')
            ->followingRedirects()
            ->put('/admin/settings/capacity', $this->restaurant([
                'slot_interval_minutes' => 17,
                'capacity_mode' => 'person',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<select id="f-slot_interval_minutes"[^>]*aria-invalid="true"/', $content);
        $this->assertMatchesRegularExpression('/<input type="radio" name="capacity_mode" value="person" checked/', $content);
        $this->assertMatchesRegularExpression('/<div data-show-if="capacity_mode:person,hybrid"\s*>/', $content);
    }

    public function test_a_stored_interval_outside_the_list_stays_selectable(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 20]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('<option value="20" selected', false);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant(['slot_interval_minutes' => 20]))
            ->assertOk();
    }
}

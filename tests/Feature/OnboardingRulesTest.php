<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnboardingRulesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'min_lead_minutes' => 30, 'max_advance_days' => 90, 'min_party_online' => 1, 'max_party_online' => 8,
            'booking_confirmation_mode' => 'manual', 'require_email_confirmation' => '1', 'default_duration_minutes' => 105,
        ], $overrides);
    }

    public function test_the_step_writes_its_fields_and_leaves_everything_else(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'duration_rules' => [['min_party' => 6, 'max_party' => 12, 'duration' => 150]],
            'reminder_enabled' => false, 'feedback_enabled' => false, 'refund_mode' => 'full', 'guest_address' => 'du',
            'waitlist_enabled' => false, 'cancellation_deadline_minutes' => 240,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload())->assertOk();
        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload(['max_party_online' => 10]))->assertOk();

        $s = $setup['location']->settings()->first();
        $this->assertSame([30, 90, 10, 105, false, true], [(int) $s->min_lead_minutes, (int) $s->max_advance_days, (int) $s->max_party_online, (int) $s->default_duration_minutes, (bool) $s->auto_confirm, (bool) $s->require_email_confirmation]);
        $this->assertSame([['min_party' => 6, 'max_party' => 12, 'duration' => 150]], $s->duration_rules);
        $this->assertSame([false, false, 'full', 'du', false, 240], [(bool) $s->reminder_enabled, (bool) $s->feedback_enabled, $s->refund_mode, $s->guest_address, (bool) $s->waitlist_enabled, (int) $s->cancellation_deadline_minutes]);
    }

    public function test_limits_come_from_the_booking_settings(): void
    {
        config(['swayy.booking.party_online_max' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload(['max_party_online' => 13]))
            ->assertStatus(422)->assertJsonValidationErrors('max_party_online');
        $this->actingAs($owner)->get('/admin/onboarding')->assertSee('max="12"', false);
    }

    public function test_the_wizard_uses_the_new_routes(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/onboarding')
            ->assertSee(route('admin.onboarding.rules'), false)
            ->assertSee(route('admin.locations.logo.store', $setup['location']), false)
            ->assertSee(route('admin.settings.opening-hours'), false)
            ->assertDontSee("fetch('/admin/settings'", false)
            // Die Woche aus dem Assistenten ersetzt die gespeicherte, auch eine leere (gleicher Vertrag wie die Seite Öffnungszeiten).
            ->assertSee("hours_sent: '1'", false)
            // Die Zeilen der Öffnungszeiten entfernt ein Handler am Container, kein Inline-Handler am Knopf.
            ->assertDontSee("this.closest('.hour-row')", false);
    }

    public function test_the_wizard_shows_the_stored_values_and_the_limits_of_the_settings(): void
    {
        config(['swayy.booking.duration_min' => 20, 'swayy.booking.duration_max' => 300, 'swayy.booking.advance_max_days' => 200, 'swayy.booking.lead_max_minutes' => 5000]);
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['min_lead_minutes' => 45, 'max_advance_days' => 120, 'default_duration_minutes' => 135, 'max_party_online' => 14]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/onboarding')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="obDuration" type="number" value="135" min="20" max="300"/', $html);
        $this->assertMatchesRegularExpression('/id="obMinLead" type="number" value="45" min="0" max="5000"/', $html);
        $this->assertMatchesRegularExpression('/id="obMaxAdvance" type="number" value="120" min="1" max="200"/', $html);
        $this->assertMatchesRegularExpression('/id="obMaxParty" type="number" value="14"/', $html);
        $this->assertStringContainsString('Übliche Besuchsdauer', $html);
    }

    public function test_the_duration_follows_the_limits_of_the_capacity_page(): void
    {
        config(['swayy.booking.duration_max' => 300]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload(['default_duration_minutes' => 301]))
            ->assertStatus(422)->assertJsonValidationErrors('default_duration_minutes');
        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload(['default_duration_minutes' => 300]))->assertOk();
        $this->assertSame(300, (int) $setup['location']->settings()->first()->default_duration_minutes);
    }

    public function test_a_missing_value_names_the_field_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload(['min_lead_minutes' => '', 'require_email_confirmation' => '']))
            ->assertStatus(422);

        $this->assertStringContainsString('Mindestvorlauf', $response->json('errors.min_lead_minutes.0'));
        $this->assertStringContainsString('E-Mail-Adresse bestätigen lassen', $response->json('errors.require_email_confirmation.0'));
    }

    public function test_a_salon_step_writes_lead_time_and_confirmation_only(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $setup['location']->settings()->update(['min_party_online' => 2, 'max_party_online' => 6, 'default_duration_minutes' => 75]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/onboarding/rules', [
            'min_lead_minutes' => 20, 'max_advance_days' => 45, 'booking_confirmation_mode' => 'auto', 'require_email_confirmation' => '0',
        ])->assertOk();

        $s = $setup['location']->settings()->first();
        $this->assertSame([20, 45, true, false], [(int) $s->min_lead_minutes, (int) $s->max_advance_days, (bool) $s->auto_confirm, (bool) $s->require_email_confirmation]);
        $this->assertSame([2, 6, 75], [(int) $s->min_party_online, (int) $s->max_party_online, (int) $s->default_duration_minutes]);

        $html = (string) $this->actingAs($owner)->get('/admin/onboarding')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="obDuration"', $html);
        $this->assertStringNotContainsString('id="obMaxParty"', $html);
        $this->assertStringContainsString('id="obMinLead"', $html);
    }

    public function test_saving_the_step_is_logged_and_needs_the_right_to_change_booking_rules(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->putJson('/admin/onboarding/rules', $this->payload())->assertForbidden();
        $this->assertNull(AuditLog::where('action', 'onboarding.rules_updated')->first());

        $this->actingAs($owner)->putJson('/admin/onboarding/rules', $this->payload())->assertOk();

        $entry = AuditLog::where('action', 'onboarding.rules_updated')->firstOrFail();
        $this->assertSame(30, $entry->new_values['min_lead_minutes']);
        $this->assertSame('Einrichtung: Buchungsregeln gespeichert', $entry->actionLabel());
        $this->assertSame('Einrichtung', $entry->area());
    }
}

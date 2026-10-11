<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\NotificationsController;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\LocationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NotificationsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'guest_address' => 'du',
            'reminder_enabled' => '1',
            'reminder_hours_before' => 6,
            'sms_reminder_enabled' => '0',
            'feedback_enabled' => '1',
            'feedback_hours_after' => 30,
            'feedback_redirect_min_score' => 5,
            'feedback_external_url' => 'https://bewertung.example.test/r/abc',
            'owner_notification_enabled' => '1',
            'owner_notification_email' => 'team@example.test',
        ], $override);
    }

    public function test_owner_sees_the_page_with_its_cards(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/notifications')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('id="anrede"', false)
            ->assertSee('id="bestaetigung"', false)
            ->assertSee('id="erinnerung"', false)
            ->assertSee('id="feedback"', false)
            ->assertSee('id="betrieb"', false)
            ->assertSee('href="'.route('admin.templates.index').'#vorlage-reservation_reminder"', false)
            ->assertSee('href="'.route('admin.templates.index').'#vorlage-feedback_request"', false)
            ->assertSee('data-minutes-hint', false);
        $this->assertMatchesRegularExpression('/data-nav-key="notifications"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/notifications')->assertForbidden();
        $this->actingAs($host)->putJson('/admin/settings/notifications', $this->payload())->assertForbidden();
    }

    public function test_saving_writes_only_notification_fields(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_lead_minutes' => 45,
            'slot_interval_minutes' => 15,
            'refund_mode' => 'manual',
            'confetti_on_booking' => false,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload())
            ->assertOk()
            ->assertExactJson(['message' => 'Benachrichtigungen gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame('du', $settings->guest_address);
        $this->assertTrue($settings->reminder_enabled);
        $this->assertSame(6, $settings->reminder_hours_before);
        $this->assertFalse($settings->sms_reminder_enabled);
        $this->assertTrue($settings->feedback_enabled);
        $this->assertSame(30, $settings->feedback_hours_after);
        $this->assertSame(5, $settings->feedback_redirect_min_score);
        $this->assertSame('https://bewertung.example.test/r/abc', $settings->feedback_external_url);
        $this->assertTrue($settings->owner_notification_enabled);
        $this->assertSame('team@example.test', $settings->owner_notification_email);
        $this->assertSame(45, $settings->min_lead_minutes);
        $this->assertSame(15, $settings->slot_interval_minutes);
        $this->assertSame('manual', $settings->refund_mode);
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue(AuditLog::where('action', 'location.notifications_updated')->exists());
    }

    public function test_switches_can_be_turned_off(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => true, 'feedback_enabled' => true, 'owner_notification_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'reminder_enabled' => '0', 'feedback_enabled' => '0', 'owner_notification_enabled' => '0',
        ]))->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->reminder_enabled);
        $this->assertFalse($settings->feedback_enabled);
        $this->assertFalse($settings->owner_notification_enabled);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.notifications.reminder_hours_max' => 48, 'swayy.notifications.feedback_hours_max' => 72]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('max="48"', false)
            ->assertSee('max="72"', false);

        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'reminder_hours_before' => 49, 'feedback_hours_after' => 73,
        ]))->assertStatus(422);

        $this->assertStringContainsString('höchstens 48', $response->json('errors.reminder_hours_before.0'));
        $this->assertStringContainsString('höchstens 72', $response->json('errors.feedback_hours_after.0'));
    }

    public function test_an_overlong_review_link_is_refused_before_the_database(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $url = 'https://bewertung.example.test/'.str_repeat('a', 260);
        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload(['feedback_external_url' => $url]))
            ->assertStatus(422);

        $this->assertStringContainsString('255', $response->json('errors.feedback_external_url.0'));
    }

    public function test_an_insecure_review_link_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload(['feedback_external_url' => 'http://bewertung.example.test']))
            ->assertStatus(422);

        $this->assertSame(
            'Der Link zum Bewertungsportal muss mit https:// beginnen. Bitte den Link aus deinem Bewertungsportal kopieren.',
            $response->json('errors.feedback_external_url.0'),
        );
    }

    public function test_sms_reminder_hint_points_to_sms_settings_until_connected(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('SMS-Versand ist noch nicht eingerichtet.')
            ->assertSee('href="'.route('admin.settings.sms.show').'"', false);

        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => 'sevenio',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'test-key', 'sender_id' => 'Swayy'])),
        ]);

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertDontSee('SMS-Versand ist noch nicht eingerichtet.');
    }

    public function test_salons_read_appointments(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertOk()
            ->assertSee('vor dem Termin')
            ->assertDontSee('vor der Reservierung');
    }

    public function test_lower_limits_come_from_the_settings(): void
    {
        config(['swayy.notifications.reminder_hours_min' => 3, 'swayy.notifications.feedback_hours_min' => 2]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('min="3"', false)
            ->assertSee('min="2"', false);

        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'reminder_hours_before' => 2, 'feedback_hours_after' => 1,
        ]))->assertStatus(422);

        $this->assertStringContainsString('mindestens 3', $response->json('errors.reminder_hours_before.0'));
        $this->assertStringContainsString('mindestens 2', $response->json('errors.feedback_hours_after.0'));

        $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'reminder_hours_before' => 3, 'feedback_hours_after' => 2,
        ]))->assertOk();
    }

    public function test_the_lengths_of_link_and_address_come_from_the_settings(): void
    {
        config(['swayy.notifications.feedback_url_max' => 60, 'swayy.notifications.owner_email_max' => 50]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('maxlength="60"', false)
            ->assertSee('maxlength="50"', false);

        $url = 'https://bewertung.example.test/'.str_repeat('a', 30);
        $email = str_repeat('a', 40).'@example.test';
        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'feedback_external_url' => $url, 'owner_notification_email' => $email,
        ]))->assertStatus(422);

        $this->assertStringContainsString('höchstens 60 Zeichen', $response->json('errors.feedback_external_url.0'));
        $this->assertStringContainsString('höchstens 50 Zeichen', $response->json('errors.owner_notification_email.0'));
    }

    public function test_a_missing_switch_is_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->payload();
        unset($payload['reminder_enabled']);
        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $payload)->assertStatus(422);

        $this->assertStringContainsString('E-Mail-Erinnerung', $response->json('errors.reminder_enabled.0'));
        $this->assertStringContainsString('Seite neu laden', $response->json('errors.reminder_enabled.0'));
    }

    public function test_every_rejection_names_the_field_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload([
            'guest_address' => 'ihr',
            'reminder_hours_before' => 'bald',
            'feedback_hours_after' => '',
            'feedback_redirect_min_score' => 9,
            'owner_notification_email' => 'kein-konto',
        ]))->assertStatus(422);

        $this->assertSame('Bitte „du“ oder „Sie“ wählen.', $response->json('errors.guest_address.0'));
        $this->assertSame('Bitte die Stunden als ganze Zahl eintragen.', $response->json('errors.reminder_hours_before.0'));
        $this->assertSame('Bitte eintragen, wie viele Stunden nach dem Besuch die Anfrage kommt.', $response->json('errors.feedback_hours_after.0'));
        $this->assertSame('Bitte eine Zahl von 1 bis 5 Sternen wählen.', $response->json('errors.feedback_redirect_min_score.0'));
        $this->assertSame('Die Adresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: team@dein-betrieb.de', $response->json('errors.owner_notification_email.0'));
    }

    public function test_a_classic_post_returns_to_the_page_with_the_errors_and_the_input(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/notifications')
            ->followingRedirects()
            ->put('/admin/settings/notifications', $this->payload(['guest_address' => 'ihr', 'reminder_hours_before' => 999]))
            ->assertOk()
            ->assertSee('id="f-guest_address-error"', false)
            ->assertSee('aria-describedby="f-guest_address-error"', false)
            ->assertSee('Bitte „du“ oder „Sie“ wählen.')
            ->assertSee('id="f-reminder_hours_before-error"', false)
            ->assertSee('value="999"', false);
    }

    public function test_optional_blocks_follow_the_saved_switches(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => false, 'feedback_enabled' => true, 'owner_notification_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/notifications')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-show-if="reminder_enabled:1"\s+hidden\s/', $html);
        $this->assertMatchesRegularExpression('/data-show-if="feedback_enabled:1"\s+class=/', $html);
        $this->assertMatchesRegularExpression('/data-show-if="owner_notification_enabled:1"\s+hidden\s/', $html);
    }

    public function test_the_address_for_the_info_explains_its_fallback(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['email' => 'kontakt@standort.example.test']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('Leer: an die Kontaktadresse des Standorts (kontakt@standort.example.test).')
            ->assertSee('placeholder="kontakt@standort.example.test"', false)
            ->assertDontSee('Ohne Adresse hier und am Standort');

        $setup['location']->update(['email' => null]);

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('Bitte eintragen. Ohne Adresse hier und am Standort kommt die Info nirgends an.')
            ->assertDontSee('Leer: an die Kontaktadresse');
    }

    public function test_roles_without_template_and_sms_rights_get_a_hint_instead_of_dead_links(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/notifications')
            ->assertOk()
            ->assertDontSee('#vorlage-', false)
            ->assertDontSee('href="'.route('admin.settings.sms.show').'"', false)
            ->assertSee('ändert ein Administrator des Betriebs unter Vorlagen')
            ->assertSee('SMS-Versand ist noch nicht eingerichtet.');
    }

    public function test_a_connection_of_another_business_does_not_count_as_sms_ready(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        IntegrationConnection::create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => null,
            'provider' => 'sevenio',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'fremd', 'sender_id' => 'Fremd'])),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/notifications')
            ->assertSee('SMS-Versand ist noch nicht eingerichtet.');
    }

    public function test_saving_leaves_other_locations_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $other['location']->settings()->update(['reminder_hours_before' => 12, 'guest_address' => 'Sie']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload())->assertOk();

        $untouched = LocationSettings::withoutGlobalScopes()->where('location_id', $other['location']->id)->firstOrFail();
        $this->assertSame(12, $untouched->reminder_hours_before);
        $this->assertSame('Sie', $untouched->guest_address);
    }

    public function test_the_log_records_old_and_new_values_with_german_labels(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_hours_before' => 24, 'feedback_external_url' => null]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/notifications', $this->payload())->assertOk();

        $log = AuditLog::where('action', 'location.notifications_updated')->firstOrFail();
        $this->assertSame('Benachrichtigungen geändert', $log->actionLabel());
        $changes = collect($log->fieldChanges())->keyBy('field');
        $this->assertSame('Erinnerung: Stunden vorher', $changes['reminder_hours_before']['label']);
        $this->assertSame('24', $changes['reminder_hours_before']['from']);
        $this->assertSame('6', $changes['reminder_hours_before']['to']);
        $this->assertSame('Link zum Bewertungsportal', $changes['feedback_external_url']['label']);
        $this->assertSame('Anrede', $changes['guest_address']['label']);
        $this->assertSame('du', $changes['guest_address']['to']);
    }

    public function test_every_field_of_the_page_has_its_own_label_in_the_log(): void
    {
        $old = array_fill_keys(NotificationsController::FIELDS, 'a');
        $new = array_fill_keys(NotificationsController::FIELDS, 'b');
        // Die Anrede erscheint als „Du“ oder „Sie“, jeder andere Wert als „Sie“.
        $old['guest_address'] = 'du';
        $changes = (new AuditLog(['old_values' => $old, 'new_values' => $new]))->fieldChanges();

        $this->assertCount(count(NotificationsController::FIELDS), $changes);
        foreach ($changes as $change) {
            $this->assertNotSame(Str::headline($change['field']), $change['label'], "{$change['field']} hat keine eigene Bezeichnung im Protokoll");
        }
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'notifications');
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertGreaterThanOrEqual(5, $targets->count());

        $response = $this->actingAs($owner)->get('/admin/settings/notifications');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }

    /**
     * Die alte Sammelseite sendet nur angehakte Kästchen: ein fehlender
     * Schalter gilt als „aus“, die Grenzen sind dieselben.
     */
    public function test_the_old_combined_page_still_saves_without_switches(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => true, 'sms_reminder_enabled' => true, 'feedback_enabled' => true, 'owner_notification_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-rules', $this->legacyPayload([
            'reminder_hours_before' => 36,
            'feedback_hours_after' => 20,
            'feedback_external_url' => 'https://bewertung.example.test/r/xyz',
            'owner_notification_email' => 'team@example.test',
        ]))->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->reminder_enabled);
        $this->assertFalse($settings->sms_reminder_enabled);
        $this->assertFalse($settings->feedback_enabled);
        $this->assertFalse($settings->owner_notification_enabled);
        $this->assertSame(36, $settings->reminder_hours_before);
        $this->assertSame(20, $settings->feedback_hours_after);
        $this->assertSame('https://bewertung.example.test/r/xyz', $settings->feedback_external_url);
        $this->assertSame('team@example.test', $settings->owner_notification_email);
    }

    public function test_the_old_combined_page_keeps_the_length_of_the_review_link_within_the_column(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $url = 'https://bewertung.example.test/'.str_repeat('a', 260);
        $response = $this->actingAs($owner)->putJson('/admin/settings/booking-rules', $this->legacyPayload(['feedback_external_url' => $url]))
            ->assertStatus(422);

        $this->assertStringContainsString('höchstens 255 Zeichen', $response->json('errors.feedback_external_url.0'));
    }

    public function test_the_old_combined_page_offers_the_configured_hour_limits(): void
    {
        config([
            'swayy.notifications.reminder_hours_min' => 2, 'swayy.notifications.reminder_hours_max' => 96,
            'swayy.notifications.feedback_hours_min' => 4, 'swayy.notifications.feedback_hours_max' => 120,
        ]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings')
            ->assertSee('name="reminder_hours_before" min="2" max="96"', false)
            ->assertSee('name="feedback_hours_after" min="4" max="120"', false);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function legacyPayload(array $override = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 30,
            'default_duration_minutes' => 120,
            'buffer_minutes' => 0,
            'capacity_mode' => 'table',
            'min_lead_minutes' => 60,
            'max_advance_days' => 90,
            'cancellation_deadline_minutes' => 120,
            'modification_deadline_minutes' => 120,
            'min_party_online' => 1,
            'max_party_online' => 8,
            'booking_confirmation_mode' => 'auto',
            'reminder_hours_before' => 24,
            'refund_mode' => 'off',
            'refund_percent' => 100,
            'refund_processing' => 'immediate',
            'guest_address' => 'Sie',
            'feedback_hours_after' => 18,
            'feedback_redirect_min_score' => 4,
        ], $override);
    }

    public function test_the_old_combined_page_follows_the_same_limits(): void
    {
        config(['swayy.notifications.reminder_hours_max' => 48]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $settings = $setup['location']->effectiveSettings();
        $response = $this->actingAs($owner)->putJson('/admin/settings/booking-rules', [
            'slot_interval_minutes' => $settings->slot_interval_minutes,
            'default_duration_minutes' => $settings->default_duration_minutes,
            'buffer_minutes' => $settings->buffer_minutes,
            'capacity_mode' => $settings->capacity_mode,
            'min_lead_minutes' => $settings->min_lead_minutes,
            'max_advance_days' => $settings->max_advance_days,
            'cancellation_deadline_minutes' => $settings->cancellation_deadline_minutes,
            'modification_deadline_minutes' => $settings->modification_deadline_minutes,
            'min_party_online' => $settings->min_party_online,
            'max_party_online' => $settings->max_party_online,
            'reminder_hours_before' => 49,
            'refund_mode' => 'off',
            'refund_percent' => 100,
            'refund_processing' => 'immediate',
            'guest_address' => 'Sie',
            'feedback_hours_after' => 18,
            'feedback_redirect_min_score' => 4,
        ])->assertStatus(422);

        $this->assertStringContainsString('höchstens 48', $response->json('errors.reminder_hours_before.0'));
    }
}

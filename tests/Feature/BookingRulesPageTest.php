<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingRulesPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function rules(array $override = []): array
    {
        return array_merge([
            'min_lead_minutes' => 90,
            'max_advance_days' => 60,
            'min_party_online' => 2,
            'max_party_online' => 12,
            'large_group_email' => 'gruppen@beispiel.test',
            'cancellation_deadline_minutes' => 1440,
            'modification_deadline_minutes' => 240,
            'booking_confirmation_mode' => 'manual',
            'require_email_confirmation' => '1',
            'waitlist_enabled' => '0',
            'walkins_enabled' => '1',
        ], $override);
    }

    public function test_owner_sees_the_page_with_its_sections(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['auto_confirm' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/rules')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('id="zeitraum"', false)
            ->assertSee('id="gruppen"', false)
            ->assertSee('id="fristen"', false)
            ->assertSee('id="bestaetigung"', false)
            ->assertSee('id="warteliste"', false)
            ->assertSee('<input type="hidden" name="waitlist_enabled" value="0">', false)
            ->assertSee('data-minutes-hint', false)
            ->assertSee('im Reservierungsbuch')
            ->assertSee('Warteliste und Walk-ins')
            ->assertSee('im Live-Board an einen freien Tisch');
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/name="booking_confirmation_mode" value="manual" checked/', $html);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-rules"[^>]*aria-current="page"/', $html);
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/settings/rules')->assertForbidden();
        $this->actingAs($staff)->putJson('/admin/settings/rules', $this->rules())->assertForbidden();
    }

    public function test_saving_writes_only_booking_rules(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'buffer_minutes' => 25,
            'capacity_mode' => 'person',
            'reminder_hours_before' => 6,
            'confetti_on_booking' => false,
            'guest_address' => 'du',
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules())
            ->assertOk()
            ->assertExactJson(['message' => 'Buchungsregeln gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame(90, $settings->min_lead_minutes);
        $this->assertSame(60, $settings->max_advance_days);
        $this->assertSame(2, $settings->min_party_online);
        $this->assertSame(12, $settings->max_party_online);
        $this->assertSame('gruppen@beispiel.test', $settings->large_group_email);
        $this->assertSame(1440, $settings->cancellation_deadline_minutes);
        $this->assertSame(240, $settings->modification_deadline_minutes);
        $this->assertFalse($settings->auto_confirm);
        $this->assertFalse($settings->request_only);
        $this->assertTrue($settings->require_email_confirmation);
        $this->assertFalse($settings->waitlist_enabled);
        $this->assertTrue($settings->walkins_enabled);
        $this->assertSame(25, $settings->buffer_minutes);
        $this->assertSame('person', $settings->capacity_mode);
        $this->assertSame(6, $settings->reminder_hours_before);
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertSame('du', $settings->guest_address);
        $this->assertTrue(AuditLog::where('action', 'location.booking_rules_updated')->exists());
    }

    public function test_every_switch_can_be_turned_off(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'require_email_confirmation' => true,
            'waitlist_enabled' => true,
            'walkins_enabled' => true,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules([
            'require_email_confirmation' => '0',
            'waitlist_enabled' => '0',
            'walkins_enabled' => '0',
        ]))->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->require_email_confirmation);
        $this->assertFalse($settings->waitlist_enabled);
        $this->assertFalse($settings->walkins_enabled);
    }

    /**
     * LocationSettings::freigabeVonHand() gilt bei request_only oder fehlendem auto_confirm.
     * Die Seite zeigt daraus eine Wahl und schreibt sie als auto_confirm zurück; request_only
     * wird dabei immer zurückgesetzt.
     *
     * @return array<string, array{bool, bool, string}>
     */
    public static function storedConfirmationFlags(): array
    {
        return [
            'sofort' => [false, true, 'auto'],
            'von Hand' => [false, false, 'manual'],
            'nur Anfrage' => [true, true, 'manual'],
            'Anfrage und von Hand' => [true, false, 'manual'],
        ];
    }

    #[DataProvider('storedConfirmationFlags')]
    public function test_the_confirmation_choice_follows_the_stored_flags_and_writes_them_back(bool $requestOnly, bool $autoConfirm, string $preselected): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['request_only' => $requestOnly, 'auto_confirm' => $autoConfirm]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $other = $preselected === 'auto' ? 'manual' : 'auto';
        $html = (string) $this->actingAs($owner)->get('/admin/settings/rules')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="booking_confirmation_mode" value="'.$preselected.'" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="booking_confirmation_mode" value="'.$other.'" checked/', $html);

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['booking_confirmation_mode' => 'auto']))->assertOk();
        $settings = $setup['location']->settings()->first();
        $this->assertTrue($settings->auto_confirm);
        $this->assertFalse($settings->request_only);
        $this->assertFalse($settings->freigabeVonHand());

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['booking_confirmation_mode' => 'manual']))->assertOk();
        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->auto_confirm);
        $this->assertFalse($settings->request_only);
        $this->assertTrue($settings->freigabeVonHand());
    }

    public function test_an_empty_group_address_is_stored_as_none(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['large_group_email' => 'gruppen@beispiel.test']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['large_group_email' => '']))->assertOk();

        $this->assertNull($setup['location']->settings()->first()->large_group_email);
    }

    public function test_a_missing_switch_is_an_error(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->rules();
        unset($payload['waitlist_enabled']);

        $this->actingAs($owner)->putJson('/admin/settings/rules', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.waitlist_enabled.0', 'Warteliste anbieten ist ein Pflichtfeld.');
    }

    public function test_the_largest_group_must_not_be_smaller_than_the_smallest(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['min_party_online' => 6, 'max_party_online' => 4]))
            ->assertStatus(422)
            ->assertJsonPath('errors.max_party_online.0', 'Die größte Gruppe ist kleiner als die kleinste. Bitte bei „Größte Gruppe“ mindestens 6 eintragen.');
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.booking.advance_max_days' => 30, 'swayy.booking.lead_max_minutes' => 600]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/rules')
            ->assertOk()
            ->assertSee('max="30"', false)
            ->assertSee('max="600"', false);

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['max_advance_days' => 60]))
            ->assertStatus(422)
            ->assertJsonPath('errors.max_advance_days.0', 'Vorausbuchung darf höchstens 30 sein.');
    }

    public function test_a_rejected_form_without_javascript_keeps_the_input(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['auto_confirm' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/rules')
            ->followingRedirects()
            ->put('/admin/settings/rules', $this->rules(['max_advance_days' => 0, 'booking_confirmation_mode' => 'manual']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input type="radio" name="booking_confirmation_mode" value="manual" checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/<input type="radio" name="booking_confirmation_mode" value="auto" checked/', $content);
        $this->assertMatchesRegularExpression('/<input id="f-max_advance_days"[^>]*aria-invalid="true"/', $content);
    }

    public function test_salons_see_and_save_only_the_rules_that_apply_to_them(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update([
            'min_party_online' => 3,
            'max_party_online' => 9,
            'large_group_email' => 'gruppen@beispiel.test',
            'walkins_enabled' => true,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $page = (string) $this->actingAs($owner)->get('/admin/settings/rules')->assertOk()->getContent();
        // Die Seitennavigation führt Suchbegriffe aller Seiten; geprüft wird der Inhalt des Formulars.
        $this->assertSame(1, preg_match('/<form id="formRules".*?<\/form>/s', $page, $form));
        $form = $form[0];

        foreach (['zeitraum', 'fristen', 'bestaetigung', 'warteliste'] as $section) {
            $this->assertStringContainsString('id="'.$section.'"', $form);
        }
        foreach (['id="gruppen"', 'name="min_party_online"', 'name="large_group_email"', 'name="walkins_enabled"', 'Walk-ins', 'Tisch', 'Reservierungsbuch'] as $restaurantOnly) {
            $this->assertStringNotContainsString($restaurantOnly, $form);
        }
        $this->assertStringContainsString('als Anfrage unter Termine', $form);
        $this->assertStringContainsString('Ist alles belegt, setzt dein Team Gäste auf die Warteliste.', $form);
        // Ein Angebot braucht einen Tisch; die Hilfe verspricht es Salons nicht.
        $this->assertStringNotContainsString('Angebot', $form);

        $this->actingAs($owner)->putJson('/admin/settings/rules', [
            'min_lead_minutes' => 30,
            'max_advance_days' => 45,
            'cancellation_deadline_minutes' => 720,
            'modification_deadline_minutes' => 360,
            'booking_confirmation_mode' => 'auto',
            'require_email_confirmation' => '0',
            'waitlist_enabled' => '1',
        ])->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertSame(30, $settings->min_lead_minutes);
        $this->assertSame(45, $settings->max_advance_days);
        $this->assertTrue($settings->auto_confirm);
        $this->assertTrue($settings->waitlist_enabled);
        $this->assertSame(3, $settings->min_party_online);
        $this->assertSame(9, $settings->max_party_online);
        $this->assertSame('gruppen@beispiel.test', $settings->large_group_email);
        $this->assertTrue($settings->walkins_enabled);
    }
}

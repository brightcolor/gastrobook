<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\BookingFormController;
use App\Models\AuditLog;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingFormPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, string>  $override
     * @return array{fields: array<string, string>}
     */
    private function fields(array $override = []): array
    {
        return ['fields' => array_merge([
            'email' => 'required',
            'phone' => 'optional',
            'occasion' => 'optional',
            'allergies' => 'optional',
            'note' => 'optional',
        ], $override)];
    }

    /** Das Auswahlfeld eines Feldes aus der Seite. */
    private function selectOf(string $html, string $field): string
    {
        $this->assertSame(1, preg_match('/<select id="f-fields-'.$field.'" name="fields\['.$field.'\]".*?<\/select>/s', $html, $select), $field);

        return $select[0];
    }

    public function test_owner_sees_every_field_with_its_current_rule(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['field_rules' => json_encode(['phone' => 'hidden'])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('data-count-many=":count Felder geändert"', false)
            ->assertSee('data-change-item="feld-email"', false)
            ->assertSee('name="fields[allergies]"', false)
            ->assertSee('Den Namen fragt das Formular immer ab.');
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/name="fields\[phone\]"[\s\S]*?<option value="hidden" selected>/', $html);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-form"[^>]*aria-current="page"/', $html);
    }

    public function test_every_field_offers_every_choice(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();

        foreach (BookingFormController::FIELDS as $field) {
            $select = $this->selectOf($html, $field);
            foreach (BookingFormController::CHOICES as $value => $label) {
                $this->assertStringContainsString('<option value="'.$value.'"', $select);
                $this->assertStringContainsString('>'.$label.'</option>', $select);
            }
        }
    }

    public function test_the_warning_appears_when_email_and_phone_are_hidden(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->getContent();
        $this->assertMatchesRegularExpression('/data-show-if="fields\[email\]:hidden;fields\[phone\]:hidden" role="note"\s+hidden/', $html);

        $setup['location']->settings()->update(['field_rules' => json_encode(['email' => 'hidden', 'phone' => 'hidden'])]);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->getContent();
        $this->assertMatchesRegularExpression('/data-show-if="fields\[email\]:hidden;fields\[phone\]:hidden" role="note"\s+class=/', $html);
    }

    public function test_roles_without_business_settings_neither_see_nor_open_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/booking-form')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/field-rules', $this->fields())->assertForbidden();

        $keys = [];
        foreach (Navigation::fromConfig()->settings($manager, $setup['tenant'], $setup['location'], null) as $group) {
            array_push($keys, ...array_map(fn (NavItem $item) => $item->key, $group->items));
        }
        $this->assertNotContains('booking-form', $keys);
        $this->assertContains('booking-rules', $keys);
    }

    public function test_saving_stores_the_rules_and_answers_with_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/field-rules', ['fields' => [
            'email' => 'required', 'phone' => 'optional', 'occasion' => 'hidden', 'allergies' => 'required', 'note' => 'optional',
        ]])->assertOk()->assertExactJson(['message' => 'Formularfelder gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame('hidden', $settings->fieldRule('occasion'));
        $this->assertSame('required', $settings->fieldRule('allergies'));
        $this->assertTrue(AuditLog::where('action', 'location.field_rules_updated')->exists());
    }

    public function test_every_choice_can_be_saved_for_every_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        foreach (array_keys(BookingFormController::CHOICES) as $choice) {
            $this->actingAs($owner)
                ->putJson('/admin/settings/field-rules', ['fields' => array_fill_keys(BookingFormController::FIELDS, $choice)])
                ->assertOk();

            $settings = $setup['location']->settings()->first();
            foreach (BookingFormController::FIELDS as $field) {
                $this->assertSame($choice, $settings->fieldRule($field), "{$field} = {$choice}");
            }
        }
    }

    public function test_saving_keeps_other_rules_and_other_settings_and_writes_the_change_to_the_audit_log(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update([
            'field_rules' => ['address' => 'required', 'phone' => 'hidden'],
            'buffer_minutes' => 25,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['phone' => 'required']))->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertSame('required', $settings->fieldRule('phone'));
        $this->assertSame('required', $settings->fieldRule('address'));
        $this->assertSame(25, $settings->buffer_minutes);

        $log = AuditLog::where('action', 'location.field_rules_updated')->latest('id')->first();
        $this->assertSame('hidden', $log->old_values['phone']);
        $this->assertSame('required', $log->new_values['phone']);
    }

    public function test_saving_leaves_the_rules_of_another_business_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $foreign = $this->createTenantSetup();
        $foreign['location']->settings->update(['field_rules' => ['phone' => 'hidden']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['phone' => 'required']))->assertOk();

        $this->assertSame('hidden', $foreign['location']->settings()->withoutGlobalScopes()->first()->fieldRule('phone'));
        $this->assertSame('required', $setup['location']->settings()->first()->fieldRule('phone'));
    }

    public function test_a_value_outside_the_choices_is_refused_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['phone' => 'immer']))
            ->assertStatus(422);

        // Feldnamen mit Punkt: ohne assertJsonPath.
        $this->assertSame('Der gewählte Wert für Telefon ist ungültig.', $response->json('errors')['fields.phone'][0] ?? null);
        $this->assertSame('required', $setup['location']->settings()->first()->fieldRule('phone'));
    }

    public function test_a_missing_field_is_an_error(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->fields();
        unset($payload['fields']['allergies']);

        $response = $this->actingAs($owner)->putJson('/admin/settings/field-rules', $payload)->assertStatus(422);

        $this->assertArrayHasKey('fields.allergies', $response->json('errors'));
    }

    public function test_a_rejected_form_without_javascript_keeps_the_input_and_marks_the_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/booking-form')
            ->followingRedirects()
            ->put('/admin/settings/field-rules', $this->fields(['email' => 'immer', 'phone' => 'hidden', 'occasion' => 'required']))
            ->assertOk()
            ->getContent();

        // Die gesendeten gültigen Werte bleiben stehen.
        $this->assertStringContainsString('<option value="hidden" selected>', $this->selectOf($content, 'phone'));
        $this->assertStringContainsString('<option value="required" selected>', $this->selectOf($content, 'occasion'));
        // Der ungültige Wert fällt auf den gespeicherten zurück: Pflichtfeld bleibt gewählt.
        $this->assertStringContainsString('<option value="required" selected>', $this->selectOf($content, 'email'));
        // Der Fehler steht am Feld und ist mit ihm verbunden.
        $this->assertMatchesRegularExpression('/<select id="f-fields-email"[^>]*aria-invalid="true"[^>]*aria-describedby="f-fields-email-help f-fields-email-error"/', $content);
        $this->assertMatchesRegularExpression('/<p id="f-fields-email-error"[^>]*>\s*Der gewählte Wert für E-Mail ist ungültig\./', $content);
        $this->assertDoesNotMatchRegularExpression('/<select id="f-fields-phone"[^>]*aria-invalid/', $content);
    }

    public function test_the_old_combined_page_still_posts_to_the_same_route(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings')
            ->assertOk()
            ->assertSee('action="'.route('admin.settings.field-rules').'"', false);

        $this->actingAs($owner)->from('/admin/settings')->put('/admin/settings/field-rules', $this->fields(['occasion' => 'hidden']))
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('success', 'Formularfelder gespeichert.');

        $this->assertSame('hidden', $setup['location']->settings()->first()->fieldRule('occasion'));
    }

    public function test_salons_see_and_save_only_the_note(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings->update(['field_rules' => ['email' => 'hidden', 'phone' => 'required', 'note' => 'optional']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $page = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();
        // Die Seitennavigation führt Suchbegriffe aller Seiten; geprüft wird der Inhalt des Formulars.
        $this->assertSame(1, preg_match('/<form id="formFields".*?<\/form>/s', $page, $form));
        $form = $form[0];

        $this->assertStringContainsString('name="fields[note]"', $form);
        foreach (['email', 'phone', 'occasion', 'allergies'] as $field) {
            $this->assertStringNotContainsString('name="fields['.$field.']"', $form);
        }
        // Die Buchung eines Termins verlangt die E-Mail-Adresse immer; die Warnung gilt dort nicht.
        $this->assertStringNotContainsString('data-show-if', $form);
        $this->assertStringContainsString('Name und E-Mail-Adresse fragt das Formular für Termine immer ab', $form);
        // Wörter des Restaurants fehlen.
        foreach (['Küche', 'Tisch', 'Gericht', 'Essen', 'Allergi'] as $restaurantOnly) {
            $this->assertStringNotContainsString($restaurantOnly, $form);
        }
        // Salons kennen „Freiwillig“ und „Ausgeblendet“; ein Pflichtfeld erzwingt die Buchungsseite für Termine nicht.
        $this->assertStringContainsString('<option value="hidden"', $form);
        $this->assertStringContainsString('<option value="optional"', $form);
        $this->assertStringNotContainsString('<option value="required"', $form);

        $this->actingAs($owner)->putJson('/admin/settings/field-rules', ['fields' => ['note' => 'hidden']])
            ->assertOk()
            ->assertExactJson(['message' => 'Formularfelder gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame('hidden', $settings->fieldRule('note'));
        // Die übrigen Regeln bleiben, wie sie waren.
        $this->assertSame('hidden', $settings->fieldRule('email'));
        $this->assertSame('required', $settings->fieldRule('phone'));
    }

    public function test_a_salon_with_a_stored_required_note_sees_freiwillig_and_the_old_page_keeps_saving(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings->update(['field_rules' => ['note' => 'required']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="optional" selected>', $this->selectOf($html, 'note'));

        // Die alte Sammelseite sendet alle fünf Felder, auch für Salons.
        $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['note' => 'required']))->assertOk();
        $this->assertSame('required', $setup['location']->settings()->first()->fieldRule('note'));
    }
}

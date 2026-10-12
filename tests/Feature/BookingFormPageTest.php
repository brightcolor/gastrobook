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
        $this->assertStringContainsString('<option value="hidden" selected>', $this->selectOf($html, 'phone'));
        $this->assertMatchesRegularExpression('/data-nav-key="booking-form"[^>]*aria-current="page"/', $html);
    }

    /**
     * Die Werte der Auswahl eines Feldes in der Reihenfolge der Seite.
     *
     * @return list<string>
     */
    private function offeredValues(string $select): array
    {
        preg_match_all('/<option value="([a-z]+)"/', $select, $matches);

        return $matches[1];
    }

    public function test_every_field_offers_the_choices_the_booking_page_honours(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();

        $this->assertSame(BookingFormController::FIELDS, array_keys(BookingFormController::FIELD_CHOICES));
        foreach (BookingFormController::FIELDS as $field) {
            $select = $this->selectOf($html, $field);
            $this->assertSame(BookingFormController::FIELD_CHOICES[$field], $this->offeredValues($select), $field);
            foreach (BookingFormController::FIELD_CHOICES[$field] as $value) {
                $this->assertStringContainsString('>'.BookingFormController::CHOICES[$value].'</option>', $select);
            }
        }
        // Die Buchungsseite erzwingt nur E-Mail und Telefon.
        $this->assertSame(['hidden', 'optional', 'required'], $this->offeredValues($this->selectOf($html, 'email')));
        $this->assertSame(['hidden', 'optional', 'required'], $this->offeredValues($this->selectOf($html, 'phone')));
        $this->assertSame(['hidden', 'optional'], $this->offeredValues($this->selectOf($html, 'occasion')));
        $this->assertSame(['hidden', 'optional'], $this->offeredValues($this->selectOf($html, 'allergies')));
        $this->assertSame(['hidden', 'optional'], $this->offeredValues($this->selectOf($html, 'note')));
    }

    public function test_a_stored_required_on_a_field_without_that_choice_shows_as_freiwillig(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['field_rules' => ['allergies' => 'required', 'email' => 'required']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();

        $allergies = $this->selectOf($html, 'allergies');
        $this->assertStringContainsString('<option value="optional" selected>Freiwillig</option>', $allergies);
        $this->assertStringNotContainsString('<option value="required"', $allergies);
        // E-Mail behält das Pflichtfeld.
        $this->assertStringContainsString('<option value="required" selected>Pflichtfeld</option>', $this->selectOf($html, 'email'));
    }

    public function test_the_page_promises_a_required_field_only_where_it_applies(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-form')
            ->assertOk()
            ->assertSee('E-Mail und Telefon kannst du zusätzlich zum Pflichtfeld machen.')
            ->assertDontSee('ob Gäste es ausfüllen müssen');
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
        $this->assertSame('Bitte für Telefon „Ausgeblendet“, „Freiwillig“ oder „Pflichtfeld“ wählen.', $response->json('errors')['fields.phone'][0] ?? null);
        $this->assertSame('required', $setup['location']->settings()->first()->fieldRule('phone'));
    }

    public function test_the_message_lists_the_choices_the_field_offers(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['occasion' => 'immer', 'allergies' => '']))
            ->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Bitte für Anlass „Ausgeblendet“ oder „Freiwillig“ wählen.', $errors['fields.occasion'][0] ?? null);
        // Eine leere Auswahl bekommt denselben nächsten Schritt.
        $this->assertSame('Bitte für Allergien / Unverträglichkeiten „Ausgeblendet“ oder „Freiwillig“ wählen.', $errors['fields.allergies'][0] ?? null);
    }

    public function test_a_missing_field_is_one_message_with_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->fields();
        unset($payload['fields']['allergies']);

        $response = $this->actingAs($owner)->putJson('/admin/settings/field-rules', $payload)->assertStatus(422);

        $this->assertSame(['fields'], array_keys($response->json('errors')));
        $this->assertSame(
            'Das Formular ist unvollständig: Es fehlen Felder, etwa weil sich der Betriebstyp seit dem Laden der Seite geändert hat. Bitte die Seite neu laden und erneut speichern.',
            $response->json('errors.fields.0')
        );
        $this->assertSame($response->json('errors.fields.0'), $response->json('message'));
    }

    /**
     * Ein Reiter, der als Salon geladen wurde, sendet nur die Anmerkung. Hat
     * der Betrieb inzwischen auf Restaurant gewechselt, fehlen vier Felder:
     * eine Meldung, keine vier gleichlautenden.
     */
    public function test_a_form_from_a_salon_tab_sent_to_a_restaurant_is_refused_once(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['field_rules' => ['phone' => 'hidden']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/field-rules', ['fields' => ['note' => 'hidden']])
            ->assertStatus(422);

        $this->assertCount(1, $response->json('errors'));
        $this->assertSame('hidden', $setup['location']->settings()->first()->fieldRule('phone'));
        $this->assertSame('optional', $setup['location']->settings()->first()->fieldRule('note'));
    }

    public function test_a_missing_field_without_javascript_shows_the_message_on_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/booking-form')
            ->followingRedirects()
            ->put('/admin/settings/field-rules', ['fields' => ['note' => 'hidden']])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bitte die Seite neu laden und erneut speichern.', $content);
    }

    public function test_a_rejected_form_without_javascript_keeps_the_input_and_marks_the_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/booking-form')
            ->followingRedirects()
            ->put('/admin/settings/field-rules', $this->fields(['email' => 'immer', 'phone' => 'hidden', 'occasion' => 'hidden']))
            ->assertOk()
            ->getContent();

        // Die gesendeten gültigen Werte bleiben stehen.
        $this->assertStringContainsString('<option value="hidden" selected>', $this->selectOf($content, 'phone'));
        $this->assertStringContainsString('<option value="hidden" selected>', $this->selectOf($content, 'occasion'));
        // Der ungültige Wert fällt auf den gespeicherten zurück: Pflichtfeld bleibt gewählt.
        $this->assertStringContainsString('<option value="required" selected>', $this->selectOf($content, 'email'));
        // Der Fehler steht am Feld und ist mit ihm verbunden.
        $this->assertMatchesRegularExpression('/<select id="f-fields-email"[^>]*aria-invalid="true"[^>]*aria-describedby="f-fields-email-help f-fields-email-error"/', $content);
        $this->assertMatchesRegularExpression('/<p id="f-fields-email-error"[^>]*>\s*Bitte für E-Mail „Ausgeblendet“, „Freiwillig“ oder „Pflichtfeld“ wählen\./', $content);
        $this->assertDoesNotMatchRegularExpression('/<select id="f-fields-phone"[^>]*aria-invalid/', $content);
    }

    public function test_a_classic_form_post_goes_back_to_the_page_with_the_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-form')
            ->assertOk()
            ->assertSee('action="'.route('admin.settings.field-rules').'"', false);

        $this->actingAs($owner)->from('/admin/settings/booking-form')->put('/admin/settings/field-rules', $this->fields(['occasion' => 'hidden']))
            ->assertRedirect('/admin/settings/booking-form')
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
        $setup['location']->settings->update(['field_rules' => ['note' => 'required', 'email' => 'hidden', 'phone' => 'required', 'occasion' => 'hidden']]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="optional" selected>', $this->selectOf($html, 'note'));

        // Die alte Sammelseite sendet alle fünf Felder, auch für Salons. Gespeichert wird nur die Anmerkung.
        $this->actingAs($owner)->putJson('/admin/settings/field-rules', $this->fields(['note' => 'required', 'email' => 'optional', 'phone' => 'optional', 'occasion' => 'required']))->assertOk();
        $settings = $setup['location']->settings()->first();
        $this->assertSame('required', $settings->fieldRule('note'));
        $this->assertSame('hidden', $settings->fieldRule('email'));
        $this->assertSame('required', $settings->fieldRule('phone'));
        $this->assertSame('hidden', $settings->fieldRule('occasion'));
    }
}

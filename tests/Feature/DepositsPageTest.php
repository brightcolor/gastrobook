<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\DepositRule;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class DepositsPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function rule(array $override = []): array
    {
        return array_merge([
            'name' => 'Gruppen ab 8',
            'min_party_size' => 8,
            'amount_per_person' => '12.5',
            'flat_amount' => '',
            'from_time' => '',
            'until_time' => '',
            'payment_deadline_minutes' => 90,
            'cancel_unpaid_automatically' => '1',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $attributes
     */
    private function storedRule(array $setup, array $attributes = []): DepositRule
    {
        return DepositRule::create($attributes + [
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Silvester', 'type' => 'deposit',
            'amount_per_person_minor' => 2000, 'flat_amount_minor' => 0, 'currency' => 'EUR', 'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['owner'] = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['service'] = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Balayage',
            'duration_minutes' => 120, 'price_minor' => 15000, 'currency' => 'EUR', 'is_active' => true,
        ]);

        return $setup;
    }

    public function test_the_page_lists_rules_and_the_refund_card(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        DepositRule::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Silvester', 'type' => 'deposit', 'amount_per_person_minor' => 2000, 'flat_amount_minor' => 0, 'currency' => 'EUR', 'payment_deadline_minutes' => 60, 'cancel_unpaid_automatically' => true]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk();

        $response->assertSee('Einstellungen › Zahlungen')
            ->assertSee('Gilt für Standort')
            ->assertSee('id="regeln"', false)
            ->assertSee('id="erstattung"', false)
            ->assertSee('id="regel-neu"', false)
            ->assertSee('Silvester')
            ->assertSee('20,00 €')
            ->assertSee('name="refund_mode"', false)
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="deposits"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_a_rule_is_created_with_exact_cents(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => '0.10', 'flat_amount' => '5']))
            ->assertOk()
            ->assertJsonPath('message', 'Anzahlungsregel „Gruppen ab 8“ angelegt.')
            ->assertJsonPath('reload', true);

        $rule = DepositRule::firstOrFail();
        $this->assertSame(10, $rule->amount_per_person_minor);
        $this->assertSame(500, $rule->flat_amount_minor);
        $this->assertSame(90, $rule->payment_deadline_minutes);
        $this->assertSame($setup['location']->id, $rule->location_id);
    }

    public function test_amounts_from_json_numbers_are_converted_without_rounding_errors(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => 1.15, 'flat_amount' => 19.99]))->assertOk();

        $rule = DepositRule::firstOrFail();
        $this->assertSame(115, $rule->amount_per_person_minor);
        $this->assertSame(1999, $rule->flat_amount_minor);
    }

    public function test_an_empty_flat_amount_counts_as_zero(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['flat_amount' => '']))->assertOk();

        $this->assertSame(0, DepositRule::firstOrFail()->flat_amount_minor);
    }

    public function test_a_comma_amount_names_the_format(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => '12,345']))->assertStatus(422);

        $this->assertStringContainsString('Punkt', $response->json('errors.amount_per_person.0'));
        $this->assertSame(0, DepositRule::count());
    }

    public function test_every_amount_that_is_no_euro_amount_names_the_format(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        foreach (['12,5', '1e2', '12.345', '-1', 'zehn'] as $amount) {
            $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => $amount, 'flat_amount' => $amount]))->assertStatus(422);
            $this->assertSame('Bitte den Betrag in Euro mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 12.50.', $response->json('errors.amount_per_person.0'), $amount);
            $this->assertSame('Bitte den Betrag in Euro mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 12.50.', $response->json('errors.flat_amount.0'), $amount);
        }
        $this->assertSame(0, DepositRule::count());
    }

    public function test_a_missing_amount_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => '']))->assertStatus(422);

        $this->assertStringContainsString('Bitte den Betrag pro Person in Euro eintragen', $response->json('errors.amount_per_person.0'));
    }

    public function test_a_foreign_room_is_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreignRoom = Room::withoutGlobalScopes()->where('location_id', $other['location']->id)->firstOrFail();
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['room_id' => $foreignRoom->id]))->assertStatus(422);

        $this->assertStringContainsString('neu laden', $response->json('errors.room_id.0'));
        $this->assertSame(0, DepositRule::count());
    }

    public function test_a_room_of_another_location_of_the_same_business_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $room = Room::factory()->create(['tenant_id' => $setup['tenant']->id, 'location_id' => $second->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['room_id' => $room->id]))
            ->assertStatus(422)->assertJsonValidationErrors('room_id');
        $this->assertSame(0, DepositRule::count());
    }

    public function test_a_foreign_service_is_refused_with_a_reason(): void
    {
        $setup = $this->salon();
        $other = $this->salon();
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->postJson('/admin/settings/deposit-rules', $this->rule(['service_id' => $other['service']->id]))
            ->assertStatus(422);

        $this->assertStringContainsString('neu laden', $response->json('errors.service_id.0'));
        $this->assertStringContainsString('Leistung', $response->json('errors.service_id.0'));
        $this->assertSame(0, DepositRule::count());
    }

    public function test_both_foreign_ids_are_named_together(): void
    {
        $setup = $this->salon();
        $other = $this->salon();
        $foreignRoom = Room::withoutGlobalScopes()->where('location_id', $other['location']->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/settings/deposit-rules', $this->rule(['service_id' => $other['service']->id, 'room_id' => $foreignRoom->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['service_id', 'room_id']);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.deposits.amount_max' => 50, 'swayy.deposits.deadline_max' => 120]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['amount_per_person' => '51', 'payment_deadline_minutes' => 121]))
            ->assertStatus(422);

        $this->assertStringContainsString('50', $response->json('errors.amount_per_person.0'));
        $this->assertStringContainsString('120', $response->json('errors.payment_deadline_minutes.0'));
        $this->actingAs($owner)->get('/admin/settings/deposits')->assertSee('max="120"', false);
    }

    public function test_all_limits_apply_with_values_other_than_the_defaults(): void
    {
        config(['swayy.deposits.amount_max' => 20, 'swayy.deposits.deadline_min' => 30, 'swayy.deposits.deadline_max' => 240,
            'swayy.deposits.party_size_max' => 12, 'swayy.deposits.name_max' => 25]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule([
            'name' => str_repeat('N', 26), 'min_party_size' => 13, 'amount_per_person' => '20.01', 'flat_amount' => '21', 'payment_deadline_minutes' => 29,
        ]))->assertStatus(422);

        $this->assertStringContainsString('25', $response->json('errors.name.0'));
        $this->assertStringContainsString('12', $response->json('errors.min_party_size.0'));
        $this->assertStringContainsString('20', $response->json('errors.amount_per_person.0'));
        $this->assertStringContainsString('20', $response->json('errors.flat_amount.0'));
        $this->assertStringContainsString('30', $response->json('errors.payment_deadline_minutes.0'));
        $this->assertSame(0, DepositRule::count());

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule([
            'name' => str_repeat('N', 25), 'min_party_size' => 12, 'amount_per_person' => '20', 'flat_amount' => '20', 'payment_deadline_minutes' => 30,
        ]))->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['payment_deadline_minutes' => 241]))->assertStatus(422);

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*maxlength="25"/', $html);
        $this->assertMatchesRegularExpression('/name="min_party_size"[^>]*max="12"/', $html);
        $this->assertMatchesRegularExpression('/name="amount_per_person"[^>]*max="20"/', $html);
        $this->assertMatchesRegularExpression('/name="flat_amount"[^>]*max="20"/', $html);
        $this->assertMatchesRegularExpression('/name="payment_deadline_minutes"[^>]*min="30"/', $html);
        $this->assertMatchesRegularExpression('/name="payment_deadline_minutes"[^>]*max="240"/', $html);
    }

    public function test_an_empty_deadline_uses_the_default_from_the_settings(): void
    {
        config(['swayy.deposits.deadline_default' => 45]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['payment_deadline_minutes' => '']))->assertOk();

        $this->assertSame(45, DepositRule::firstOrFail()->payment_deadline_minutes);
        $this->actingAs($owner)->get('/admin/settings/deposits')->assertSee('value="45"', false);
    }

    public function test_the_limits_stay_within_the_columns_and_are_documented(): void
    {
        // deposit_rules: name varchar(255), min_party_size und payment_deadline_minutes smallint (32767). Der Betrag einer Buchung
        // (Grundbetrag plus Betrag pro Person mal 100 Personen) steht in integer-Spalten (2147483647 Cent).
        [$config, $warnings] = $this->swayyConfig([
            'SWAYY_DEPOSIT_AMOUNT_MAX' => '99999999', 'SWAYY_DEPOSIT_DEADLINE_MAX' => '90000', 'SWAYY_DEPOSIT_PARTY_SIZE_MAX' => '5000',
            'SWAYY_DEPOSIT_NAME_MAX' => '4000', 'SWAYY_DEPOSIT_DEADLINE_MIN' => '5000', 'SWAYY_DEPOSIT_DEADLINE_DEFAULT' => '99999',
        ]);
        $this->assertSame(212622, $config['deposits']['amount_max']);
        $this->assertSame(32767, $config['deposits']['deadline_max']);
        $this->assertSame(100, $config['deposits']['party_size_max']);
        $this->assertSame(255, $config['deposits']['name_max']);
        $this->assertSame(120, $config['deposits']['deadline_min']);
        $this->assertLessThanOrEqual($config['deposits']['deadline_max'], $config['deposits']['deadline_default']);
        $this->assertGreaterThanOrEqual($config['deposits']['deadline_min'], $config['deposits']['deadline_default']);
        $this->assertLessThanOrEqual(2147483647, ($config['deposits']['amount_max'] * 100 + $config['deposits']['amount_max']) * 100);
        foreach (['AMOUNT_MAX', 'DEADLINE_MAX', 'PARTY_SIZE_MAX', 'NAME_MAX', 'DEADLINE_MIN', 'DEADLINE_DEFAULT'] as $name) {
            $this->assertStringContainsString('SWAYY_DEPOSIT_'.$name, $warnings);
        }

        [$config] = $this->swayyConfig([
            'SWAYY_DEPOSIT_AMOUNT_MAX' => '1', 'SWAYY_DEPOSIT_DEADLINE_MAX' => '1', 'SWAYY_DEPOSIT_PARTY_SIZE_MAX' => '1',
            'SWAYY_DEPOSIT_NAME_MAX' => '1', 'SWAYY_DEPOSIT_DEADLINE_MIN' => '1',
        ]);
        $this->assertSame(10, $config['deposits']['amount_max']);
        $this->assertSame(60, $config['deposits']['deadline_max']);
        $this->assertSame(2, $config['deposits']['party_size_max']);
        $this->assertSame(20, $config['deposits']['name_max']);
        $this->assertSame(5, $config['deposits']['deadline_min']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(['amount_max' => 10000, 'deadline_min' => 10, 'deadline_max' => 10080, 'deadline_default' => 60, 'party_size_max' => 100, 'name_max' => 120], $config['deposits']);

        $this->assertDocumentedInEnvExample([
            'SWAYY_DEPOSIT_AMOUNT_MAX', 'SWAYY_DEPOSIT_DEADLINE_MIN', 'SWAYY_DEPOSIT_DEADLINE_MAX',
            'SWAYY_DEPOSIT_DEADLINE_DEFAULT', 'SWAYY_DEPOSIT_PARTY_SIZE_MAX', 'SWAYY_DEPOSIT_NAME_MAX',
        ]);
    }

    public function test_the_largest_allowed_values_are_stored_and_read_back(): void
    {
        config(['swayy.deposits.amount_max' => 212622, 'swayy.deposits.deadline_max' => 32767, 'swayy.deposits.party_size_max' => 100, 'swayy.deposits.name_max' => 255]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $name = str_repeat('ä', 255);
        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule([
            'name' => $name, 'min_party_size' => 100, 'amount_per_person' => '212622', 'flat_amount' => '212622.00', 'payment_deadline_minutes' => 32767,
        ]))->assertOk();

        $rule = DepositRule::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($name, $rule->name);
        $this->assertSame(100, $rule->min_party_size);
        $this->assertSame(21262200, $rule->amount_per_person_minor);
        $this->assertSame(21262200, $rule->flat_amount_minor);
        // Auch mit 100 Personen passt der Betrag einer Buchung in die integer-Spalten.
        $this->assertLessThanOrEqual(2147483647, $rule->amountFor(100));
        $this->assertSame(32767, $rule->payment_deadline_minutes);
        $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->assertSee('212.622,00 €');
    }

    public function test_a_rule_of_another_location_answers_404(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = DepositRule::create(['tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id, 'name' => 'Fremd', 'type' => 'deposit', 'amount_per_person_minor' => 100, 'flat_amount_minor' => 0, 'currency' => 'EUR', 'payment_deadline_minutes' => 60, 'cancel_unpaid_automatically' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule())->assertNotFound();
        $this->actingAs($owner)->deleteJson('/admin/settings/deposit-rules/'.$rule->id)->assertNotFound();
    }

    public function test_a_rule_of_another_location_of_the_same_business_answers_404(): void
    {
        $setup = $this->createTenantSetup();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = $this->storedRule(['tenant' => $setup['tenant'], 'location' => $second], ['name' => 'Anderer Standort']);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule())->assertNotFound();
        $this->actingAs($owner)->deleteJson('/admin/settings/deposit-rules/'.$rule->id)->assertNotFound();
        $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->assertDontSee('Anderer Standort');
        $this->assertSame(1, DepositRule::withoutGlobalScopes()->count());
        $this->assertSame('Anderer Standort', $rule->fresh()->name);
    }

    public function test_deleting_answers_with_json_and_logs_once(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = DepositRule::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Weg', 'type' => 'deposit', 'amount_per_person_minor' => 100, 'flat_amount_minor' => 0, 'currency' => 'EUR', 'payment_deadline_minutes' => 60, 'cancel_unpaid_automatically' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/settings/deposit-rules/'.$rule->id)
            ->assertOk()->assertJsonPath('message', 'Anzahlungsregel „Weg“ gelöscht.');
        $this->actingAs($owner)->deleteJson('/admin/settings/deposit-rules/'.$rule->id)->assertNotFound();

        $this->assertSame(1, AuditLog::where('action', 'deposit_rule.deleted')->count());
    }

    public function test_deleting_without_javascript_redirects_back(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = $this->storedRule($setup, ['name' => 'Klassisch']);
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/deposits')->delete('/admin/settings/deposit-rules/'.$rule->id)
            ->assertRedirect('/admin/settings/deposits')
            ->assertSessionHas('success', 'Anzahlungsregel „Klassisch“ gelöscht.');
        $this->assertSame(0, DepositRule::withoutGlobalScopes()->count());
    }

    public function test_the_log_names_what_changed_in_german(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['flat_amount' => '5']))->assertOk();
        $rule = DepositRule::firstOrFail();
        $this->actingAs($owner)->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule(['amount_per_person' => '15']))->assertOk();

        $created = AuditLog::where('action', 'deposit_rule.created')->firstOrFail();
        $changes = collect($created->fieldChanges())->keyBy('field');
        $this->assertSame('Betrag pro Person (€)', $changes['amount_per_person']['label']);
        $this->assertSame('Grundbetrag (€)', $changes['flat_amount']['label']);
        $this->assertSame('Zahlungsfrist (Min.)', $changes['payment_deadline_minutes']['label']);
        $this->assertSame('Ab Personenzahl', $changes['min_party_size']['label']);
        $this->assertSame('Unbezahlte Buchungen automatisch stornieren', $changes['cancel_unpaid_automatically']['label']);
        $this->assertSame('Ja', $changes['cancel_unpaid_automatically']['to']);
        $this->assertSame('Anzahlungsregel angelegt', $created->actionLabel());
        $this->assertSame(1, AuditLog::where('action', 'deposit_rule.updated')->count());
    }

    public function test_weekdays_can_be_chosen_and_cleared(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['weekdays' => ['', '4', '5']]))->assertOk();
        $rule = DepositRule::firstOrFail();
        $this->assertSame([4, 5], $rule->weekdays);

        // Die Seite sendet immer eine leere Markierung mit; ohne Haken heißt das „an allen Tagen“.
        $this->actingAs($owner)->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule(['weekdays' => ['']]))->assertOk();
        $this->assertNull($rule->fresh()->weekdays);

        $this->actingAs($owner)->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule(['weekdays' => ['0', '6']]))->assertOk();
        $this->assertSame([0, 6], $rule->fresh()->weekdays);
    }

    public function test_the_weekday_boxes_send_no_value_for_monday_when_unchecked(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->storedRule($setup, ['weekdays' => [4, 5]]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();

        $this->assertStringContainsString('Nur an diesen Tagen', $html);
        $this->assertStringContainsString('Kein Haken: an allen Tagen', $html);
        // Montag (0) ist ein gültiger Wert, deshalb gibt es kein verstecktes Feld mit einer Null für die Tage.
        $this->assertDoesNotMatchRegularExpression('/<input type="hidden" name="weekdays\[\]" value="0"/', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="weekdays\[\]" value="">/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="weekdays\[\]"[^>]*value="4"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="weekdays\[\]"[^>]*value="5"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="checkbox"[^>]*name="weekdays\[\]"[^>]*value="3"[^>]*checked/', $html);
    }

    public function test_an_unknown_weekday_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['weekdays' => ['7']]))->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertArrayHasKey('weekdays.0', $errors);
        $this->assertStringContainsString('neu laden', $errors['weekdays.0'][0]);
        $this->assertSame(0, DepositRule::count());
    }

    public function test_saving_keeps_bindings_that_the_form_did_not_send(): void
    {
        $setup = $this->salon();
        $room = Room::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();
        $rule = $this->storedRule($setup, ['name' => 'Gebunden', 'service_id' => $setup['service']->id, 'room_id' => $room->id, 'weekdays' => [4], 'until_time' => '22:00:00']);
        $this->clearTenantContext();

        // So sieht die Anfrage der alten Sammelseite aus: ohne Leistung, Raum, Tage und Bis-Uhrzeit.
        $this->actingAs($setup['owner'])->put('/admin/settings/deposit-rules/'.$rule->id, [
            'name' => 'Gebunden 2', 'amount_per_person' => '10', 'min_party_size' => '', 'from_time' => '', 'flat_amount' => '', 'payment_deadline_minutes' => '60',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame('Gebunden 2', $rule->name);
        $this->assertSame($setup['service']->id, $rule->service_id);
        $this->assertSame($room->id, $rule->room_id);
        $this->assertSame([4], $rule->weekdays);
        $this->assertSame('22:00:00', $rule->until_time);
        $this->assertFalse($rule->cancel_unpaid_automatically);
    }

    public function test_an_empty_choice_removes_a_binding(): void
    {
        $setup = $this->salon();
        $room = Room::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();
        $rule = $this->storedRule($setup, ['service_id' => $setup['service']->id, 'room_id' => $room->id]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->putJson('/admin/settings/deposit-rules/'.$rule->id, $this->rule(['service_id' => '', 'room_id' => '']))->assertOk();

        $this->assertNull($rule->fresh()->service_id);
        $this->assertNull($rule->fresh()->room_id);
    }

    public function test_the_time_window_must_end_after_it_starts(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['from_time' => '20:00', 'until_time' => '18:00']))->assertStatus(422);
        $this->assertSame('„Bis“ liegt vor „Von“. Bitte eine spätere Uhrzeit wählen.', $response->json('errors.until_time.0'));

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['from_time' => '', 'until_time' => '18:00']))->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['from_time' => '18:00', 'until_time' => '22:30']))->assertOk();
        $this->assertSame(2, DepositRule::count());

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['from_time' => '25:00']))->assertStatus(422);
        $this->assertStringContainsString('18:30', $response->json('errors.from_time.0'));
    }

    public function test_the_switch_is_saved_as_sent_and_missing_means_off(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['cancel_unpaid_automatically' => '0', 'name' => 'Aus']))->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['cancel_unpaid_automatically' => '1', 'name' => 'An']))->assertOk();
        // Die alte Sammelseite sendet das Kästchen nur angehakt.
        $unchecked = $this->rule(['name' => 'Alte Seite']);
        unset($unchecked['cancel_unpaid_automatically']);
        $this->actingAs($owner)->post('/admin/settings/deposit-rules', $unchecked)->assertRedirect();

        $this->assertFalse(DepositRule::where('name', 'Aus')->firstOrFail()->cancel_unpaid_automatically);
        $this->assertTrue(DepositRule::where('name', 'An')->firstOrFail()->cancel_unpaid_automatically);
        $this->assertFalse(DepositRule::where('name', 'Alte Seite')->firstOrFail()->cancel_unpaid_automatically);

        $response = $this->actingAs($owner)->postJson('/admin/settings/deposit-rules', $this->rule(['cancel_unpaid_automatically' => 'vielleicht']))->assertStatus(422);
        $this->assertStringContainsString('neu laden', $response->json('errors.cancel_unpaid_automatically.0'));
    }

    public function test_the_drawers_send_the_switch_even_when_unchecked(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = $this->storedRule($setup, ['cancel_unpaid_automatically' => false]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<input type="hidden" name="cancel_unpaid_automatically" value="0">'));
        $this->assertStringContainsString('id="regel-'.$rule->id.'"', $html);
        $this->assertStringContainsString('Unbezahlte Buchungen nach Ablauf der Frist stornieren', $html);
        // Neue Regeln starten mit Haken, die gespeicherte Regel ohne.
        $this->assertMatchesRegularExpression('/id="regel-neu-storno"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="regel-'.$rule->id.'-storno"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="regel-'.$rule->id.'-storno"[^>]*checked/', $html);
    }

    public function test_the_refund_card_writes_only_refund_fields(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_hours_before' => 6, 'min_lead_minutes' => 45]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'auto', 'refund_percent' => 50, 'refund_processing' => 'scheduled'])
            ->assertOk()->assertJsonPath('message', 'Erstattung gespeichert.');

        $settings = $setup['location']->settings()->first();
        $this->assertSame('auto', $settings->refund_mode);
        $this->assertSame(50, $settings->refund_percent);
        $this->assertSame('scheduled', $settings->refund_processing);
        $this->assertSame(6, $settings->reminder_hours_before);
        $this->assertSame(45, $settings->min_lead_minutes);
        $this->assertTrue(AuditLog::where('action', 'location.refunds_updated')->exists());
    }

    public function test_the_refund_log_keeps_old_and_new_values(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['refund_mode' => 'manual', 'refund_percent' => 80, 'refund_processing' => 'immediate']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'auto', 'refund_percent' => 50, 'refund_processing' => 'immediate'])->assertOk();

        $log = AuditLog::where('action', 'location.refunds_updated')->firstOrFail();
        $this->assertSame('Erstattung der Anzahlung geändert', $log->actionLabel());
        $this->assertSame(['refund_mode' => 'manual', 'refund_percent' => 80, 'refund_processing' => 'immediate'], $log->old_values);
        $this->assertSame(['refund_mode' => 'auto', 'refund_percent' => 50, 'refund_processing' => 'immediate'], $log->new_values);
        $changes = collect($log->fieldChanges())->keyBy('field');
        $this->assertSame(['refund_mode', 'refund_percent'], $changes->keys()->all());
        $this->assertSame('Erstattung: Modus', $changes['refund_mode']['label']);
        $this->assertSame('Erstattung in %', $changes['refund_percent']['label']);
    }

    public function test_the_refund_card_shows_the_saved_values(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['refund_mode' => 'manual', 'refund_percent' => 75, 'refund_processing' => 'scheduled']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="refund_mode"[^>]*value="manual"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="refund_processing"[^>]*value="scheduled"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="refund_percent"[^>]*value="75"/', $html);
        $this->assertStringContainsString('data-savebar', $html);
        $this->assertStringContainsString('Gilt bei Storno innerhalb der Frist. Nach der Frist und bei Nichterscheinen behält der Betrieb die Anzahlung.', $html);
    }

    public function test_an_unknown_refund_mode_names_the_choices(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'immer', 'refund_percent' => 50, 'refund_processing' => 'immediate'])
            ->assertStatus(422);

        $this->assertSame('Bitte „Aus“, „Von Hand freigeben“ oder „Automatisch“ wählen.', $response->json('errors.refund_mode.0'));
    }

    public function test_refund_percent_and_processing_name_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        foreach (['101', '-1', 'viel', ''] as $percent) {
            $response = $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'auto', 'refund_percent' => $percent, 'refund_processing' => 'jetzt'])
                ->assertStatus(422);
            $this->assertSame('Bitte einen Prozentwert von 0 bis 100 eintragen.', $response->json('errors.refund_percent.0'), $percent);
            $this->assertSame('Bitte „Sofort“ oder „Gesammelt nach Zeitplan“ wählen.', $response->json('errors.refund_processing.0'));
        }

        foreach (['0', '100'] as $percent) {
            $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'off', 'refund_percent' => $percent, 'refund_processing' => 'immediate'])->assertOk();
        }
        $this->assertSame(100, $setup['location']->settings()->first()->refund_percent);
    }

    public function test_the_refund_card_is_replaced_by_a_sentence_without_the_right_to_set_it(): void
    {
        config(['permissions.roles.zahlungen' => ['tables.manage', 'payments.manage']]);
        $setup = $this->createTenantSetup();
        $member = $this->createMember($setup['tenant'], 'zahlungen');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($member)->get('/admin/settings/deposits')->assertOk()
            ->assertSee('id="erstattung"', false)
            ->assertSee('Die Erstattung der Anzahlung stellen Standortleitung und Administratoren des Betriebs ein.')
            ->getContent();
        $this->assertStringNotContainsString('name="refund_mode"', $html);
        $this->assertStringNotContainsString('data-savebar', $html);

        $this->actingAs($member)->putJson('/admin/settings/refunds', ['refund_mode' => 'auto', 'refund_percent' => 50, 'refund_processing' => 'immediate'])->assertForbidden();
        $this->assertSame('off', $setup['location']->settings()->first()->refund_mode);
    }

    public function test_staff_without_payment_rights_is_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/deposits')->assertForbidden();
    }

    public function test_managers_of_a_location_have_no_access_to_the_deposits(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/deposits')->assertForbidden();
        $this->actingAs($manager)->postJson('/admin/settings/deposit-rules', $this->rule())->assertForbidden();
        $this->assertSame(0, DepositRule::withoutGlobalScopes()->count());
    }

    public function test_the_refund_route_needs_the_right_for_opening_hours(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->putJson('/admin/settings/refunds', ['refund_mode' => 'auto', 'refund_percent' => 50, 'refund_processing' => 'immediate'])->assertForbidden();
    }

    public function test_without_rules_the_page_shows_one_action(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()
            ->assertSee('Noch keine Anzahlungsregeln')
            ->getContent();

        // Der Seitenkopf und der Leerzustand öffnen dieselbe Seitenleiste.
        $this->assertSame(2, substr_count($html, 'data-drawer-open="regel-neu"'));
    }

    public function test_the_list_describes_each_rule_in_one_line(): void
    {
        $setup = $this->createTenantSetup();
        $setup['room']->update(['name' => 'Terrasse']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->storedRule($setup, [
            'name' => 'Wochenende', 'amount_per_person_minor' => 1250, 'flat_amount_minor' => 500, 'min_party_size' => 8, 'weekdays' => [4, 5],
            'from_time' => '18:00:00', 'until_time' => '22:30:00', 'room_id' => $setup['room']->id, 'cancel_unpaid_automatically' => false,
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()
            ->assertSee('12,50 € p. P. · 5,00 € Grundbetrag · ab 8 Personen · Fr, Sa · ab 18:00 · bis 22:30 · Raum Terrasse · Auto-Storno aus');
    }

    public function test_the_list_asks_before_deleting_and_names_the_consequence(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = $this->storedRule($setup, ['name' => 'Silvester']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();

        $this->assertStringContainsString('Regel „Silvester“ löschen? Neue Buchungen brauchen danach keine Anzahlung mehr nach dieser Regel; offene Zahlungen bleiben.', $html);
        $this->assertStringContainsString('action="'.route('admin.settings.deposit-rules.delete', $rule).'"', $html);
        $this->assertStringContainsString('data-reload-after-save', $html);
        $this->assertStringContainsString('data-drawer-open="regel-'.$rule->id.'"', $html);
    }

    public function test_a_restaurant_chooses_a_room_and_a_salon_chooses_a_service(): void
    {
        $restaurant = $this->createTenantSetup();
        $owner = $this->createMember($restaurant['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();
        $this->assertStringContainsString('name="room_id"', $html);
        $this->assertStringNotContainsString('name="service_id"', $html);

        $salon = $this->salon();
        $this->clearTenantContext();

        $html = (string) $this->actingAs($salon['owner'])->get('/admin/settings/deposits')->assertOk()->getContent();
        $this->assertStringContainsString('name="service_id"', $html);
        $this->assertStringContainsString('Balayage', $html);
        $this->assertStringNotContainsString('name="room_id"', $html);
    }

    public function test_a_restaurant_without_rooms_shows_no_room_choice(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        Room::withoutGlobalScopes()->where('location_id', $setup['location']->id)->update(['is_active' => false]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->assertDontSee('name="room_id"', false);
    }

    public function test_an_inactive_service_with_a_rule_stays_selectable(): void
    {
        $setup = $this->salon();
        $setup['service']->update(['is_active' => false]);
        $rule = $this->storedRule($setup, ['name' => 'Bindung', 'service_id' => $setup['service']->id]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/settings/deposits')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.$setup['service']->id.'"\s+selected\s*>\s*Balayage/', $html);
        $this->assertStringContainsString('id="regel-'.$rule->id.'"', $html);
    }

    public function test_a_salon_sees_amounts_per_appointment_and_no_party_size(): void
    {
        $setup = $this->salon();
        $this->storedRule($setup, ['name' => 'Färben', 'amount_per_person_minor' => 3000]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/settings/deposits')->assertOk()->getContent();

        // Ein Termin zählt als eine Person; eine Mindestzahl an Personen gibt es dort nicht.
        $this->assertStringNotContainsString('name="min_party_size"', $html);
        $this->assertStringContainsString('Betrag pro Termin', $html);
        $this->assertStringContainsString('30,00 € pro Termin', $html);
        $this->assertStringNotContainsString('p. P.', $html);

        $restaurant = $this->createTenantSetup();
        $owner = $this->createMember($restaurant['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/deposits')->assertOk()->getContent();
        $this->assertStringContainsString('name="min_party_size"', $html);
        $this->assertStringContainsString('Betrag pro Person', $html);
        $this->assertStringNotContainsString('Betrag pro Termin', $html);
    }

    public function test_saving_a_salon_rule_keeps_a_party_size_the_form_does_not_offer(): void
    {
        $setup = $this->salon();
        $rule = $this->storedRule($setup, ['name' => 'Alt', 'min_party_size' => 4]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->putJson('/admin/settings/deposit-rules/'.$rule->id, [
            'name' => 'Neu', 'amount_per_person' => '30', 'flat_amount' => '', 'from_time' => '', 'until_time' => '',
            'payment_deadline_minutes' => 60, 'cancel_unpaid_automatically' => '1', 'service_id' => '', 'weekdays' => [''],
        ])->assertOk();

        $this->assertSame(4, $rule->fresh()->min_party_size);
    }

    public function test_the_refund_card_leaves_the_booking_rules_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_party_online' => 7, 'cancellation_deadline_minutes' => 333]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'manual', 'refund_percent' => 10, 'refund_processing' => 'immediate'])->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertSame(7, $settings->max_party_online);
        $this->assertSame(333, $settings->cancellation_deadline_minutes);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'deposits');
        $this->assertSame('admin.settings.deposits.show', $item['route']);
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $this->assertSame(['payments.manage'], $item['can']);
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->unique()->values();
        $this->assertSame(['regeln', 'erstattung'], $targets->all());

        $response = $this->actingAs($owner)->get('/admin/settings/deposits');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }
}

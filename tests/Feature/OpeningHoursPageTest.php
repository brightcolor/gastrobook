<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\SeasonPeriod;
use App\Support\OpeningHoursRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class OpeningHoursPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    public function test_the_page_shows_the_week_with_its_windows(): void
    {
        $setup = $this->createTenantSetup();
        OpeningHour::where('location_id', $setup['location']->id)->where('weekday', 0)->delete();
        OpeningHour::where('location_id', $setup['location']->id)->where('weekday', 1)->update(['service_name' => 'Abendservice']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('id="woche"', false)
            ->assertSee('id="saison"', false)
            ->assertSee('data-day="0"', false)
            ->assertSee('value="Abendservice"', false)
            ->assertSee('data-preview-url="'.route('admin.settings.opening-hours.preview').'"', false)
            ->assertDontSee('onclick', false);
        $this->assertMatchesRegularExpression('/data-day="0"[^>]*data-open="0"/', (string) $response->getContent());
        $this->assertMatchesRegularExpression('/data-nav-key="opening-hours"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_saving_replaces_the_week_and_nothing_else(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 20, 'reminder_hours_before' => 7]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 1, 'opens_at' => '11:30', 'closes_at' => '14:30', 'service_name' => 'Mittag'],
            ['weekday' => 1, 'opens_at' => '17:30', 'closes_at' => '23:00', 'service_name' => ''],
            ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00', 'service_name' => ''],
        ]])->assertOk()->assertJsonPath('message', 'Öffnungszeiten gespeichert.');

        $rows = OpeningHour::where('location_id', $setup['location']->id)->orderBy('weekday')->orderBy('opens_at')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('Mittag', $rows[0]->service_name);
        $settings = $setup['location']->settings()->first();
        $this->assertSame(20, (int) $settings->slot_interval_minutes);
        $this->assertSame(7, (int) $settings->reminder_hours_before);
    }

    public function test_an_empty_week_is_allowed(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => []])->assertOk();

        $this->assertSame(0, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_overlapping_and_zero_length_windows_are_refused(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 2, 'opens_at' => '11:00', 'closes_at' => '15:00'],
            ['weekday' => 2, 'opens_at' => '14:00', 'closes_at' => '22:00'],
            ['weekday' => 3, 'opens_at' => '12:00', 'closes_at' => '12:00'],
        ]])->assertStatus(422);

        $this->assertSame('Dieses Zeitfenster überschneidet sich mit 11:00–15:00 am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.', $response->json('errors')['hours.1.opens_at'][0]);
        $this->assertSame('Öffnet und schließt zur selben Uhrzeit. Bitte eine andere Schließzeit wählen.', $response->json('errors')['hours.2.closes_at'][0]);
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_window_count_and_name_length_come_from_the_settings(): void
    {
        config(['swayy.opening_hours.windows_per_day_max' => 2, 'swayy.opening_hours.name_max' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 4, 'opens_at' => '08:00', 'closes_at' => '10:00', 'service_name' => 'Frühstücksbuffet'],
            ['weekday' => 4, 'opens_at' => '11:00', 'closes_at' => '14:00'],
            ['weekday' => 4, 'opens_at' => '17:00', 'closes_at' => '22:00'],
        ]])->assertStatus(422);

        $this->assertStringContainsString('12', $response->json('errors')['hours.0.service_name'][0]);
        $this->assertStringContainsString('höchstens 2 Zeitfenster', $response->json('errors')['hours.2.opens_at'][0]);
    }

    public function test_seasons_are_listed_with_add_and_delete(): void
    {
        $setup = $this->createTenantSetup();
        SeasonPeriod::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'label' => 'Sommer', 'start_month' => 5, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/opening-hours')
            ->assertSee('Sommer')
            ->assertSee('id="saison-neu"', false)
            ->assertSee('data-inline-confirm', false);
    }

    public function test_a_host_has_no_access(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/opening-hours')->assertForbidden();
        $this->actingAs($host)->postJson('/admin/settings/opening-hours/preview', ['weekday' => 1, 'windows' => []])->assertForbidden();
    }

    public function test_every_day_has_its_own_fieldset_with_a_switch_without_a_name(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk()->getContent();

        foreach (['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $weekday => $name) {
            $this->assertStringContainsString('data-day="'.$weekday.'"', $html);
            $this->assertStringContainsString('<legend', $html);
            $this->assertStringContainsString($name, $html);
        }
        $this->assertSame(7, substr_count($html, 'data-day-open'));
        // Das Kästchen sendet nichts: Ein Schalter ohne Name kann keinen gespeicherten Zustand zurückschalten.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-day-open[^>]*\sname=/', $html);
        $this->assertStringContainsString('name="hours_sent"', $html);
        $this->assertStringContainsString('data-rows-template', $html);
        $this->assertStringContainsString('name="hours[__INDEX__][weekday]"', $html);
        $this->assertStringContainsString('data-hint', $html);
        $this->assertStringContainsString('id="tage-uebertragen"', $html);
        $this->assertStringContainsString('data-copy-open="0"', $html);
        $this->assertStringContainsString('data-copy-apply', $html);
        $this->assertStringContainsString('data-preview', $html);
        $this->assertStringContainsString('So sehen Gäste es', $html);
    }

    public function test_the_page_hands_the_limits_to_the_browser(): void
    {
        config([
            'swayy.opening_hours.windows_per_day_max' => 3, 'swayy.opening_hours.name_max' => 25,
            'swayy.opening_hours.season_label_max' => 20, 'swayy.ui.preview_delay_ms' => 700,
        ]);
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['default_duration_minutes' => 75]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk()->getContent();

        $this->assertStringContainsString('data-preview-delay="700"', $html);
        $this->assertStringContainsString('data-duration="75"', $html);
        $this->assertStringContainsString('data-windows-max="3"', $html);
        $this->assertStringContainsString('data-rows-max="3"', $html);
        $this->assertMatchesRegularExpression('/name="hours\[0\]\[service_name\]"[^>]*maxlength="25"/', $html);
        $this->assertMatchesRegularExpression('/name="label"[^>]*maxlength="20"/', $html);
    }

    public function test_a_form_without_open_days_saves_an_empty_week_only_with_its_marker(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Ohne Kennzeichen fehlt die Woche in der Anfrage: Der gespeicherte Stand bleibt.
        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', [])->assertStatus(422);
        $this->assertSame('Die Öffnungszeiten fehlen in der Anfrage. Bitte die Seite neu laden und noch einmal speichern.', $response->json('errors.hours.0'));
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());

        // Ein Formular, dessen Tage alle geschlossen sind, sendet nur das Kennzeichen.
        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours_sent' => '1'])
            ->assertOk()->assertJsonPath('message', 'Öffnungszeiten gespeichert.');
        $this->assertSame(0, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_a_classic_form_post_goes_back_to_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/opening-hours')
            ->put('/admin/settings/opening-hours', ['hours' => [['weekday' => 0, 'opens_at' => '10:00', 'closes_at' => '12:00']]])
            ->assertRedirect('/admin/settings/opening-hours')
            ->assertSessionHas('success', 'Öffnungszeiten gespeichert.');

        $this->actingAs($owner)->from('/admin/settings/opening-hours')
            ->put('/admin/settings/opening-hours', ['hours' => [['weekday' => 0, 'opens_at' => '12:00', 'closes_at' => '12:00']]])
            ->assertRedirect('/admin/settings/opening-hours')
            ->assertSessionHasErrors(['hours.0.closes_at']);
    }

    public function test_the_setup_wizard_sends_text_values_and_reads_errors_as_json(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner);
        $json = ['HTTP_ACCEPT' => 'application/json'];

        // Der Assistent sendet alle Werte als Text, über POST mit _method=PUT, und liest 422 als JSON.
        $this->call('POST', '/admin/settings/opening-hours', ['_method' => 'PUT', 'hours' => [
            ['weekday' => '0', 'opens_at' => '11:00', 'closes_at' => '22:00', 'service_name' => ''],
            ['weekday' => '1', 'opens_at' => '11:00', 'closes_at' => '22:00', 'service_name' => 'Abend'],
        ]], [], [], $json)->assertOk()->assertJsonPath('message', 'Öffnungszeiten gespeichert.');
        $this->assertSame(2, OpeningHour::where('location_id', $setup['location']->id)->count());

        $response = $this->call('POST', '/admin/settings/opening-hours', ['_method' => 'PUT', 'hours' => [
            ['weekday' => '0', 'opens_at' => '11:00', 'closes_at' => '22:00', 'service_name' => ''],
            ['weekday' => '0', 'opens_at' => '21:00', 'closes_at' => '23:00', 'service_name' => ''],
        ]], [], [], $json)->assertStatus(422);
        $this->assertStringContainsString('überschneidet sich mit 11:00–22:00', $response->json('errors')['hours.1.opens_at'][0]);
        $this->assertSame(2, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_unknown_weekdays_and_times_name_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 9, 'opens_at' => '25:00', 'closes_at' => ''],
            ['weekday' => 'x', 'opens_at' => '', 'closes_at' => '9:7'],
            'kaputt',
        ]])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Dieser Wochentag ist unbekannt. Bitte die Seite neu laden.', $errors['hours.0.weekday'][0]);
        $this->assertSame('Bitte die Uhrzeit als HH:MM eintragen, etwa 11:30.', $errors['hours.0.opens_at'][0]);
        $this->assertSame('Bitte bei jedem Zeitfenster eintragen, wann es endet.', $errors['hours.0.closes_at'][0]);
        $this->assertSame('Dieser Wochentag ist unbekannt. Bitte die Seite neu laden.', $errors['hours.1.weekday'][0]);
        $this->assertSame('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.', $errors['hours.1.opens_at'][0]);
        $this->assertSame('Bitte die Uhrzeit als HH:MM eintragen, etwa 11:30.', $errors['hours.1.closes_at'][0]);
        $this->assertSame('Ein Zeitfenster hat ein unbekanntes Format. Bitte die Seite neu laden und noch einmal speichern.', $errors['hours.2'][0]);
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_every_rule_has_a_message_of_its_own(): void
    {
        foreach (['hours' => OpeningHoursRules::rules(), 'windows' => OpeningHoursRules::windowRules()] as $prefix => $rules) {
            $messages = OpeningHoursRules::messages($prefix);
            foreach ($rules as $field => $list) {
                foreach ($list as $rule) {
                    // nullable lässt Werte durch und lehnt keinen ab.
                    if ($rule !== 'nullable') {
                        $this->assertArrayHasKey($field.'.'.strtok((string) $rule, ':'), $messages, "{$field}: {$rule}");
                    }
                }
            }
        }
    }

    public function test_windows_on_other_days_and_adjacent_windows_are_fine(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 0, 'opens_at' => '11:00', 'closes_at' => '14:00'],
            ['weekday' => 0, 'opens_at' => '14:00', 'closes_at' => '18:00'],
            ['weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '14:00'],
            ['weekday' => 6, 'opens_at' => '22:00', 'closes_at' => '02:00'],
            ['weekday' => 6, 'opens_at' => '02:00', 'closes_at' => '04:00'],
        ]])->assertOk();

        $this->assertSame(5, OpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_early_morning_and_late_evening_of_one_weekday_are_both_accepted_even_when_the_evening_goes_past_midnight(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Das Abendfenster endet am Samstag um 04:00; das Morgenfenster liegt am Freitag davor.
        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 4, 'opens_at' => '03:00', 'closes_at' => '10:00'],
            ['weekday' => 4, 'opens_at' => '22:00', 'closes_at' => '04:00'],
        ]])->assertOk();
        $this->assertSame(2, OpeningHour::where('location_id', $setup['location']->id)->count());

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 4, 'opens_at' => '22:00', 'closes_at' => '02:00'],
            ['weekday' => 4, 'opens_at' => '01:00', 'closes_at' => '03:00'],
        ]])->assertOk();
    }

    public function test_a_window_past_midnight_overlaps_with_a_window_of_the_next_day(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Freitag 22:00 bis Samstag 03:00 und Samstag ab 02:00 teilen eine Stunde.
        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 5, 'opens_at' => '02:00', 'closes_at' => '05:00'],
            ['weekday' => 4, 'opens_at' => '22:00', 'closes_at' => '03:00'],
        ]])->assertStatus(422);

        // Das Fenster, das auf der Zeitachse später beginnt, trägt den Hinweis und nennt Tag und Zeiten des anderen.
        $this->assertSame(
            'Dieses Zeitfenster überschneidet sich mit Freitag 22:00–03:00. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.',
            $response->json('errors')['hours.0.opens_at'][0],
        );
        $this->assertArrayNotHasKey('hours.1.opens_at', $response->json('errors'));
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());

        // Beginnt das Fenster am Samstag erst, wenn das vom Freitag endet, sind beide in Ordnung.
        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 4, 'opens_at' => '22:00', 'closes_at' => '03:00'],
            ['weekday' => 5, 'opens_at' => '03:00', 'closes_at' => '05:00'],
        ]])->assertOk();
    }

    public function test_sunday_evening_past_midnight_overlaps_with_monday_morning(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Die Woche läuft im Kreis: Sonntag 23:00 bis Montag 02:00 und Montag ab 01:00 teilen eine Stunde.
        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 6, 'opens_at' => '23:00', 'closes_at' => '02:00'],
            ['weekday' => 0, 'opens_at' => '01:00', 'closes_at' => '04:00'],
        ]])->assertStatus(422);

        $this->assertSame(
            'Dieses Zeitfenster überschneidet sich mit Sonntag 23:00–02:00. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.',
            $response->json('errors')['hours.1.opens_at'][0],
        );
        $this->assertArrayNotHasKey('hours.0.opens_at', $response->json('errors'));

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 6, 'opens_at' => '23:00', 'closes_at' => '02:00'],
            ['weekday' => 0, 'opens_at' => '02:00', 'closes_at' => '04:00'],
        ]])->assertOk();
    }

    public function test_windows_of_days_further_apart_do_not_overlap(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 0, 'opens_at' => '22:00', 'closes_at' => '04:00'],
            ['weekday' => 2, 'opens_at' => '01:00', 'closes_at' => '03:00'],
            ['weekday' => 6, 'opens_at' => '03:00', 'closes_at' => '12:00'],
        ]])->assertOk();
    }

    public function test_the_keys_of_new_rows_come_back_in_the_errors(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            '0' => ['weekday' => 2, 'opens_at' => '11:00', 'closes_at' => '15:00'],
            'n1' => ['weekday' => 2, 'opens_at' => '12:00', 'closes_at' => '13:00'],
            'c4' => ['weekday' => 3, 'opens_at' => '12:00', 'closes_at' => '12:00'],
        ]])->assertStatus(422);

        $this->assertArrayHasKey('hours.n1.opens_at', $response->json('errors'));
        $this->assertArrayHasKey('hours.c4.closes_at', $response->json('errors'));
    }

    public function test_the_limits_stay_within_the_columns_and_are_documented(): void
    {
        // opening_hours.service_name ist varchar(255), season_periods.label varchar(60).
        [$config, $warnings] = $this->swayyConfig([
            'SWAYY_OPENING_HOURS_WINDOWS_PER_DAY_MAX' => '99', 'SWAYY_OPENING_HOURS_NAME_MAX' => '4000',
            'SWAYY_OPENING_HOURS_SEASON_LABEL_MAX' => '900', 'SWAYY_OPENING_HOURS_PREVIEW_GROUPS_MAX' => '500',
            'SWAYY_UI_PREVIEW_DELAY_MS' => '90000',
        ]);
        $this->assertSame(12, $config['opening_hours']['windows_per_day_max']);
        $this->assertSame(120, $config['opening_hours']['name_max']);
        $this->assertSame(60, $config['opening_hours']['season_label_max']);
        $this->assertSame(30, $config['opening_hours']['preview_groups_max']);
        $this->assertSame(3000, $config['ui']['preview_delay_ms']);
        foreach (['WINDOWS_PER_DAY_MAX', 'NAME_MAX', 'SEASON_LABEL_MAX', 'PREVIEW_GROUPS_MAX'] as $name) {
            $this->assertStringContainsString('SWAYY_OPENING_HOURS_'.$name, $warnings);
        }
        $this->assertStringContainsString('SWAYY_UI_PREVIEW_DELAY_MS', $warnings);
        $this->assertLessThanOrEqual(255, $config['opening_hours']['name_max']);
        $this->assertLessThanOrEqual(60, $config['opening_hours']['season_label_max']);

        [$config] = $this->swayyConfig([
            'SWAYY_OPENING_HOURS_WINDOWS_PER_DAY_MAX' => '0', 'SWAYY_OPENING_HOURS_NAME_MAX' => '1',
            'SWAYY_OPENING_HOURS_SEASON_LABEL_MAX' => '1', 'SWAYY_OPENING_HOURS_PREVIEW_GROUPS_MAX' => '0',
            'SWAYY_UI_PREVIEW_DELAY_MS' => '1',
        ]);
        $this->assertSame(1, $config['opening_hours']['windows_per_day_max']);
        $this->assertSame(10, $config['opening_hours']['name_max']);
        $this->assertSame(10, $config['opening_hours']['season_label_max']);
        $this->assertSame(1, $config['opening_hours']['preview_groups_max']);
        $this->assertSame(100, $config['ui']['preview_delay_ms']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(['windows_per_day_max' => 6, 'name_max' => 60, 'season_label_max' => 60, 'preview_groups_max' => 8], $config['opening_hours']);
        $this->assertSame(400, $config['ui']['preview_delay_ms']);

        $this->assertDocumentedInEnvExample([
            'SWAYY_OPENING_HOURS_WINDOWS_PER_DAY_MAX', 'SWAYY_OPENING_HOURS_NAME_MAX', 'SWAYY_OPENING_HOURS_SEASON_LABEL_MAX',
            'SWAYY_OPENING_HOURS_PREVIEW_GROUPS_MAX', 'SWAYY_UI_PREVIEW_DELAY_MS',
        ]);
    }

    public function test_the_largest_allowed_values_are_stored_and_read_back(): void
    {
        config(['swayy.opening_hours.windows_per_day_max' => 12, 'swayy.opening_hours.name_max' => 120, 'swayy.opening_hours.season_label_max' => 60]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $name = str_repeat('ä', 120);
        $hours = [];
        foreach (range(0, 11) as $i) {
            $hours[] = ['weekday' => 3, 'opens_at' => sprintf('%02d:00', $i * 2), 'closes_at' => sprintf('%02d:30', $i * 2), 'service_name' => $i === 0 ? $name : null];
        }
        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => $hours])->assertOk();
        $label = str_repeat('ö', 60);
        $this->actingAs($owner)->postJson('/admin/settings/seasons', ['label' => $label, 'start_month' => 1, 'start_day' => 1, 'end_month' => 12, 'end_day' => 31])->assertOk();

        $rows = OpeningHour::where('location_id', $setup['location']->id)->orderBy('opens_at')->get();
        $this->assertCount(12, $rows);
        $this->assertSame($name, $rows[0]->service_name);
        $this->assertSame($label, SeasonPeriod::withoutGlobalScopes()->sole()->label);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$name.'"', $html);

        $hours[] = ['weekday' => 3, 'opens_at' => '23:00', 'closes_at' => '23:30'];
        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => $hours])->assertStatus(422);
    }

    public function test_a_season_is_created_and_asks_the_page_to_reload(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/seasons', ['label' => 'Winter', 'start_month' => 11, 'start_day' => 15, 'end_month' => 2, 'end_day' => 29])
            ->assertOk()->assertJsonPath('message', 'Saison gespeichert.')->assertJsonPath('reload', true);

        $season = SeasonPeriod::withoutGlobalScopes()->sole();
        $this->assertSame(11, $season->start_month);
        $this->assertSame(29, $season->end_day);
        $this->assertSame($setup['location']->id, $season->location_id);
    }

    public function test_season_fields_name_the_next_step(): void
    {
        config(['swayy.opening_hours.season_label_max' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/seasons', [
            'label' => str_repeat('x', 21), 'start_month' => 13, 'start_day' => 'a', 'end_month' => '', 'end_day' => 32,
        ])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Die Bezeichnung darf höchstens 20 Zeichen haben. Bitte kürzer fassen.', $errors['label'][0]);
        $this->assertSame('Bitte einen Monat aus der Liste wählen.', $errors['start_month'][0]);
        $this->assertSame('Bitte den Tag als Zahl von 1 bis 31 eintragen.', $errors['start_day'][0]);
        $this->assertSame('Bitte einen Monat aus der Liste wählen.', $errors['end_month'][0]);
        $this->assertSame('Bitte den Tag als Zahl von 1 bis 31 eintragen.', $errors['end_day'][0]);
        $this->assertSame(0, SeasonPeriod::withoutGlobalScopes()->count());
    }

    public function test_deleting_a_season_answers_with_json_and_reload(): void
    {
        $setup = $this->createTenantSetup();
        $season = SeasonPeriod::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'label' => 'Sommer', 'start_month' => 5, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/settings/seasons/'.$season->id)
            ->assertOk()->assertJsonPath('message', 'Saison gelöscht.')->assertJsonPath('reload', true);

        $this->assertSame(0, SeasonPeriod::withoutGlobalScopes()->count());
    }

    public function test_a_season_of_another_location_answers_404(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $other = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $season = SeasonPeriod::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $other->id, 'label' => 'Nebenan', 'start_month' => 5, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30]);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/settings/seasons/'.$season->id)->assertNotFound();

        $this->assertSame(1, SeasonPeriod::withoutGlobalScopes()->count());
    }

    public function test_without_the_right_for_seasons_the_list_has_no_buttons(): void
    {
        config(['permissions.roles.zeiten' => ['tables.manage', 'opening_hours.manage']]);
        $setup = $this->createTenantSetup();
        SeasonPeriod::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'label' => 'Sommer', 'start_month' => 5, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30]);
        $member = $this->createMember($setup['tenant'], 'zeiten');
        $this->clearTenantContext();

        $response = $this->actingAs($member)->get('/admin/settings/opening-hours')->assertOk()
            ->assertSee('Sommer')
            ->assertSee('id="saison"', false);
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('id="saison-neu"', $html);
        $this->assertStringNotContainsString('data-inline-confirm', $html);
        $this->assertStringNotContainsString('data-drawer-open="saison-neu"', $html);

        $this->actingAs($member)->postJson('/admin/settings/seasons', ['start_month' => 1, 'start_day' => 1, 'end_month' => 2, 'end_day' => 1])->assertForbidden();
        $this->actingAs($member)->putJson('/admin/settings/opening-hours', ['hours' => []])->assertOk();
    }

    public function test_the_empty_season_list_says_the_whole_year_is_bookable(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/opening-hours')
            ->assertSee('Keine Saison gesetzt')
            ->assertSee('Das ganze Jahr ist buchbar.');
    }

    public function test_the_log_names_what_changed_in_german(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/opening-hours', ['hours' => [
            ['weekday' => 0, 'opens_at' => '10:00', 'closes_at' => '12:00'],
            ['weekday' => 1, 'opens_at' => '10:00', 'closes_at' => '12:00'],
        ]])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/seasons', ['label' => 'Sommer', 'start_month' => 5, 'start_day' => 1, 'end_month' => 9, 'end_day' => 30])->assertOk();

        $hours = AuditLog::where('action', 'opening_hours.updated')->sole();
        $this->assertSame('Öffnungszeiten geändert', $hours->actionLabel());
        $this->assertSame([['field' => 'count', 'label' => 'Zeitfenster in der Woche', 'from' => null, 'to' => '2']], $hours->fieldChanges());

        $season = AuditLog::where('action', 'season.created')->sole();
        $this->assertSame('Saison angelegt', $season->actionLabel());
        $labels = collect($season->fieldChanges())->pluck('label', 'field')->all();
        $this->assertSame(['label' => 'Bezeichnung', 'start_month' => 'Beginn (Monat)', 'start_day' => 'Beginn (Tag)', 'end_month' => 'Ende (Monat)', 'end_day' => 'Ende (Tag)'], $labels);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'opening-hours');
        $this->assertSame('admin.settings.opening-hours.show', $item['route']);
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $this->assertSame(['opening_hours.manage'], $item['can']);
        $this->assertSame(
            ['Öffnungszeiten' => 'woche', 'Ruhetag' => 'woche', 'Zeitfenster' => 'woche', 'Saison' => 'saison', 'Buchbare Saison' => 'saison'],
            $item['keywords'],
        );

        $response = $this->actingAs($owner)->get('/admin/settings/opening-hours');
        foreach (collect($item['keywords'])->unique()->values() as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }

    public function test_a_salon_reads_customers_and_appointments(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk()
            ->assertSee('So sehen Kunden es')
            ->assertSee('Kunden finden online keine Uhrzeit')
            ->assertSee('wann Kunden online buchen können', false);
        // Der Inhalt der Seite (ohne Navigation) spricht von Kunden und Terminen.
        $main = (string) substr((string) $response->getContent(), (int) strpos((string) $response->getContent(), 'id="main-content"'));
        $this->assertStringContainsString('So sehen Kunden es', $main);
        $this->assertStringNotContainsString('Gäste', $main);
        $this->assertStringNotContainsString('Besuch', $main);
    }

    public function test_classic_form_posts_go_back_to_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/opening-hours')
            ->put(route('admin.settings.opening-hours'), ['hours' => [['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '13:00', 'service_name' => 'Frühstück']]])
            ->assertRedirect('/admin/settings/opening-hours');
        $this->actingAs($owner)->from('/admin/settings/opening-hours')
            ->post(route('admin.settings.seasons.store'), ['label' => 'Sommer', 'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31])
            ->assertRedirect('/admin/settings/opening-hours');

        $this->assertSame(1, OpeningHour::where('location_id', $setup['location']->id)->count());
        $this->assertSame(1, SeasonPeriod::withoutGlobalScopes()->count());
        $this->actingAs($owner)->get('/admin/settings/opening-hours')->assertOk()->assertSee('name="hours_sent"', false);
    }

    public function test_the_setup_wizard_empties_the_week_with_the_flag_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());

        // Der Assistent sendet hours_sent wie die Seite: Ohne Zeilen wird die Woche leer, ohne das Kennzeichen bleibt sie stehen.
        $this->actingAs($owner)->call('POST', '/admin/settings/opening-hours', ['_method' => 'PUT'], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(422);
        $this->assertSame(7, OpeningHour::where('location_id', $setup['location']->id)->count());

        $this->actingAs($owner)->call('POST', '/admin/settings/opening-hours', ['_method' => 'PUT', 'hours_sent' => '1'], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertOk();
        $this->assertSame(0, OpeningHour::where('location_id', $setup['location']->id)->count());
    }
}

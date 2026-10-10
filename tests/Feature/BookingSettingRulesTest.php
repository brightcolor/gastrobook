<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocationSettings;
use App\Support\BookingSettingRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Grenzen und Prüfregeln der Buchungseinstellungen kommen aus
 * config/swayy.php und gelten für die neuen Seiten wie für die alte
 * Sammelseite.
 */
class BookingSettingRulesTest extends TestCase
{
    public function test_the_new_settings_have_their_defaults(): void
    {
        $this->assertSame([15, 30, 60], config('swayy.booking.slot_intervals'));
        $this->assertSame(30, config('swayy.booking.duration_min'));
        $this->assertSame(480, config('swayy.booking.duration_max'));
        $this->assertSame(120, config('swayy.booking.buffer_max'));
        $this->assertSame(20, config('swayy.booking.duration_rules_max'));
        $this->assertSame(15, config('swayy.booking.rule_duration_min'));
        $this->assertSame(600, config('swayy.booking.rule_duration_max'));
        $this->assertSame(200, config('swayy.booking.rule_party_max'));
        $this->assertSame(2000, config('swayy.booking.covers_max'));
        $this->assertSame(10080, config('swayy.booking.lead_max_minutes'));
        $this->assertSame(730, config('swayy.booking.advance_max_days'));
        $this->assertSame(20160, config('swayy.booking.deadline_max_minutes'));
        $this->assertSame(100, config('swayy.booking.party_online_max'));
        $this->assertSame(50, config('swayy.tables.capacity_max'));
        $this->assertSame(5, config('swayy.salon.service_duration_min'));
        $this->assertSame(480, config('swayy.salon.service_duration_max'));
        $this->assertSame(1000, config('swayy.salon.service_price_max'));
        $this->assertSame('#0f766e', config('swayy.ui.default_staff_color'));
        $this->assertSame('#6b7280', config('swayy.ui.default_label_color'));
    }

    public function test_the_interval_choice_keeps_a_stored_value_outside_the_list(): void
    {
        config(['swayy.booking.slot_intervals' => [30, 15]]);

        $this->assertSame([15, 30], BookingSettingRules::slotIntervals());
        $this->assertSame([15, 20, 30], BookingSettingRules::slotIntervals(new LocationSettings(['slot_interval_minutes' => 20])));
    }

    public function test_salons_get_interval_buffer_and_gap_optimisation(): void
    {
        $this->assertSame(
            ['slot_interval_minutes', 'buffer_minutes', 'gap_optimization_enabled'],
            array_keys(BookingSettingRules::capacity(null, salon: true)),
        );
        $restaurant = BookingSettingRules::capacity(null, salon: false);
        $this->assertArrayHasKey('capacity_mode', $restaurant);
        $this->assertArrayHasKey('duration_rules.*.duration', $restaurant);
        $this->assertArrayNotHasKey('gap_optimization_enabled', $restaurant);
    }

    public function test_limits_follow_the_settings(): void
    {
        config(['swayy.booking.buffer_max' => 30, 'swayy.booking.party_online_max' => 12, 'swayy.booking.deadline_max_minutes' => 600]);

        $this->assertContains('max:30', BookingSettingRules::capacity(null, salon: true)['buffer_minutes']);
        $this->assertContains('max:12', BookingSettingRules::booking()['max_party_online']);
        $this->assertContains('max:600', BookingSettingRules::booking()['cancellation_deadline_minutes']);
    }

    public function test_the_messages_name_the_changed_limits(): void
    {
        config([
            'swayy.booking.duration_rules_max' => 2,
            'swayy.booking.rule_duration_min' => 20,
            'swayy.booking.rule_duration_max' => 90,
            'swayy.booking.buffer_max' => 30,
        ]);

        $row = ['min_party' => '1', 'max_party' => '2', 'duration' => '120'];
        $errors = Validator::make([
            'slot_interval_minutes' => 30,
            'default_duration_minutes' => 90,
            'buffer_minutes' => 45,
            'capacity_mode' => 'table',
            'duration_rules' => [$row, $row, $row],
        ], BookingSettingRules::capacity(null, salon: false), BookingSettingRules::messages())->errors();

        $this->assertSame(['Die Zahl der Tischzeiten ist auf 2 begrenzt. Bitte Gruppengrößen zusammenfassen.'], $errors->get('duration_rules'));
        $this->assertSame(['Eine Tischzeit darf höchstens 90 Minuten betragen.'], $errors->get('duration_rules.0.duration'));
        $this->assertTrue($errors->has('buffer_minutes'));
        $this->assertFalse($errors->has('slot_interval_minutes'));

        $tooShort = Validator::make(
            ['duration_rules' => [['min_party' => '1', 'max_party' => '2', 'duration' => '15']]],
            ['duration_rules.*.duration' => BookingSettingRules::capacity(null, salon: false)['duration_rules.*.duration']],
            BookingSettingRules::messages(),
        )->errors();

        $this->assertSame(['Eine Tischzeit muss mindestens 20 Minuten betragen.'], $tooShort->get('duration_rules.0.duration'));
    }

    public function test_the_largest_group_message_names_the_smallest_value(): void
    {
        $errors = Validator::make(
            ['min_party_online' => 6, 'max_party_online' => 4],
            ['min_party_online' => ['integer'], 'max_party_online' => BookingSettingRules::booking()['max_party_online']],
            BookingSettingRules::messages(),
        )->errors();

        $this->assertSame(['Die größte Gruppe ist kleiner als die kleinste. Bitte bei „Größte Gruppe“ mindestens 6 eintragen.'], $errors->get('max_party_online'));
    }

    public function test_a_restaurant_form_reports_the_duration_before_the_buffer(): void
    {
        $keys = array_keys(BookingSettingRules::capacity(null, salon: false));

        $this->assertSame(['slot_interval_minutes', 'default_duration_minutes', 'buffer_minutes'], array_slice($keys, 0, 3));
    }

    public function test_a_salon_form_leaves_out_group_size_and_walk_ins(): void
    {
        $restaurant = array_keys(BookingSettingRules::booking());
        $salon = array_keys(BookingSettingRules::booking(salon: true));

        $this->assertSame(
            ['min_party_online', 'max_party_online', 'large_group_email', 'walkins_enabled'],
            array_values(array_diff($restaurant, $salon)),
        );
        $this->assertSame([], array_diff($salon, $restaurant));
    }

    public function test_every_field_of_the_rules_has_a_label(): void
    {
        $keys = array_merge(
            array_keys(BookingSettingRules::capacity(null, salon: false)),
            array_keys(BookingSettingRules::capacity(null, salon: true)),
            array_keys(BookingSettingRules::booking()),
        );

        $this->assertSame([], array_values(array_diff($keys, array_keys(BookingSettingRules::attributes()))));
    }

    public function test_a_largest_value_below_the_smallest_is_raised_to_it(): void
    {
        [$config] = $this->swayyConfig([
            'SWAYY_BOOKING_DURATION_MIN' => '120',
            'SWAYY_BOOKING_DURATION_MAX' => '90',
            'SWAYY_BOOKING_RULE_DURATION_MIN' => '200',
            'SWAYY_BOOKING_RULE_DURATION_MAX' => '100',
            'SWAYY_SERVICE_DURATION_MIN' => '45',
            'SWAYY_SERVICE_DURATION_MAX' => '30',
        ]);

        $this->assertSame([120, 120], [$config['booking']['duration_min'], $config['booking']['duration_max']]);
        $this->assertSame([200, 200], [$config['booking']['rule_duration_min'], $config['booking']['rule_duration_max']]);
        $this->assertSame([45, 45], [$config['salon']['service_duration_min'], $config['salon']['service_duration_max']]);

        [$config, $warnings] = $this->swayyConfig([
            'SWAYY_BOOKING_DURATION_MIN' => '60',
            'SWAYY_BOOKING_DURATION_MAX' => '300',
        ]);

        $this->assertSame([60, 300], [$config['booking']['duration_min'], $config['booking']['duration_max']]);
        $this->assertSame('', $warnings);
    }

    public function test_limits_end_at_the_size_of_their_database_columns(): void
    {
        $names = ['SWAYY_BOOKING_COVERS_MAX', 'SWAYY_BOOKING_LEAD_MAX', 'SWAYY_BOOKING_DEADLINE_MAX'];
        $effective = fn (array $config) => [$config['booking']['covers_max'], $config['booking']['lead_max_minutes'], $config['booking']['deadline_max_minutes']];

        [$config, $warnings] = $this->swayyConfig(array_fill_keys($names, '32767'));

        $this->assertSame([32767, 32767, 32767], $effective($config));
        $this->assertSame('', $warnings);

        [$config, $warnings] = $this->swayyConfig(array_combine($names, ['70000', '43200', '32768']));

        $this->assertSame([32767, 32767, 32767], $effective($config));
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $warnings);
        }
    }

    public function test_duration_rules_are_sorted_and_empty_rows_dropped(): void
    {
        $rules = BookingSettingRules::durationRules([
            'n2' => ['min_party' => '5', 'max_party' => '8', 'duration' => '150'],
            0 => ['min_party' => '1', 'max_party' => '4', 'duration' => '90'],
            'n3' => ['min_party' => '', 'max_party' => '', 'duration' => ''],
        ]);

        $this->assertSame([
            ['min_party' => 1, 'max_party' => 4, 'duration' => 90],
            ['min_party' => 5, 'max_party' => 8, 'duration' => 150],
        ], $rules);
    }

    public function test_an_overlap_is_reported_at_the_later_row(): void
    {
        try {
            BookingSettingRules::durationRules([
                0 => ['min_party' => '1', 'max_party' => '4', 'duration' => '90'],
                'n1' => ['min_party' => '3', 'max_party' => '6', 'duration' => '120'],
            ]);
            $this->fail('Die Überschneidung ging durch.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['duration_rules.n1.min_party' => ['Die Gruppengrößen überschneiden sich: „1–4“ und „3–6“. Jede Gruppengröße darf nur in einer Regel vorkommen.']],
                $e->errors(),
            );
        }
    }

    public function test_a_reversed_range_names_the_fix_and_the_old_page_keeps_one_key(): void
    {
        try {
            BookingSettingRules::durationRules([0 => ['min_party' => '4', 'max_party' => '2', 'duration' => '90']], atField: false);
            $this->fail('Die vertauschte Spanne ging durch.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['duration_rules' => ['Bei „4 bis 2 Personen“ ist die Bis-Zahl kleiner als die Ab-Zahl. Bitte die Zahlen tauschen.']],
                $e->errors(),
            );
        }
    }

    /**
     * Liest config/swayy.php mit den angegebenen Umgebungsvariablen neu ein
     * und liefert die Einstellungen samt den Warnungen im Fehlerprotokoll.
     *
     * @param  array<string, string>  $env
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function swayyConfig(array $env): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'swayy-config');
        $previous = ini_set('error_log', $file);
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $config = require config_path('swayy.php');
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
            ini_set('error_log', $previous === false ? '' : $previous);
            $warnings = (string) file_get_contents($file);
            @unlink($file);
        }

        return [$config, $warnings];
    }
}

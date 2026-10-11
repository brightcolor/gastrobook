<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnvSetting;
use PHPUnit\Framework\TestCase;

/**
 * Ein Tippfehler in der .env soll nichts lahmlegen und nicht still
 * verschwinden: Es gilt die Vorgabe oder die naechste Grenze, und die Warnung
 * nennt die Variable.
 */
class EnvSettingTest extends TestCase
{
    private const VARIABLE = 'SWAYY_TEST_EINSTELLUNG';

    /** Was EnvSetting waehrend des letzten capture() ins Fehlerprotokoll schrieb. */
    private string $warnings = '';

    protected function tearDown(): void
    {
        unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);

        parent::tearDown();
    }

    public function test_an_unset_variable_yields_the_default(): void
    {
        $this->assertSame(10, $this->capture(fn () => EnvSetting::integer(self::VARIABLE, default: 10, min: 1, max: 60)));
        $this->assertSame('', $this->warnings);

        $this->assertSame([60, 600], $this->capture(fn () => EnvSetting::integerList(self::VARIABLE, default: [60, 600], min: 1, max: 86400)));
        $this->assertSame('', $this->warnings);

        $this->assertSame([], $this->capture(fn () => EnvSetting::networks(self::VARIABLE)));
        $this->assertSame('', $this->warnings);
    }

    public function test_a_valid_value_other_than_the_default_is_used(): void
    {
        $this->set('25');
        $this->assertSame(25, $this->capture(fn () => EnvSetting::integer(self::VARIABLE, default: 10, min: 1, max: 60)));
        $this->assertSame('', $this->warnings);

        $this->set('30, 120,900');
        $this->assertSame([30, 120, 900], $this->capture(fn () => EnvSetting::integerList(self::VARIABLE, default: [60, 600], min: 1, max: 86400)));
        $this->assertSame('', $this->warnings);

        $this->set('10.20.0.0/16, 192.168.5.10,fd00:1::/32');
        $this->assertSame(['10.20.0.0/16', '192.168.5.10', 'fd00:1::/32'], $this->capture(fn () => EnvSetting::networks(self::VARIABLE)));
        $this->assertSame('', $this->warnings);
    }

    public function test_secret_keys_keep_long_ones_and_never_name_a_value(): void
    {
        $lang = str_repeat('a', 32);
        $zweiter = str_repeat('b', 40);
        $this->set("kurz-geheim, {$lang},{$zweiter}, ".str_repeat('c', 33));

        $this->assertSame([$lang, $zweiter], $this->capture(fn () => EnvSetting::secretList(self::VARIABLE, minLength: 32, maxCount: 2)));
        $this->assertStringContainsString('Schlüssel 1 hat 11 Zeichen', $this->warnings);
        $this->assertStringContainsString('Es gelten die ersten 2', $this->warnings);
        $this->assertStringNotContainsString('kurz-geheim', $this->warnings);
        $this->assertStringNotContainsString($lang, $this->warnings);
    }

    public function test_pattern_time_and_https_url(): void
    {
        $this->set('mein-dienst');
        $this->assertSame('mein-dienst', $this->capture(fn () => EnvSetting::pattern(self::VARIABLE, 'swayy', '/^[a-z0-9-]{1,40}$/', 'passt nicht')));
        $this->set('Mein Dienst');
        $this->assertSame('swayy', $this->capture(fn () => EnvSetting::pattern(self::VARIABLE, 'swayy', '/^[a-z0-9-]{1,40}$/', 'passt nicht')));
        $this->assertStringContainsString('passt nicht. Es gilt die Vorgabe „swayy“', $this->warnings);

        $this->set('05:15');
        $this->assertSame('05:15', $this->capture(fn () => EnvSetting::time(self::VARIABLE, '04:40')));
        $this->set('25:00');
        $this->assertSame('04:40', $this->capture(fn () => EnvSetting::time(self::VARIABLE, '04:40')));

        $this->set('https://panel.example.de/');
        $this->assertSame('https://panel.example.de', $this->capture(fn () => EnvSetting::httpsUrl(self::VARIABLE)));
        $this->set('http://panel.example.de');
        $this->assertSame('', $this->capture(fn () => EnvSetting::httpsUrl(self::VARIABLE)));
        $this->assertStringContainsString('keine vollständige Adresse mit https://', $this->warnings);
    }

    public function test_a_value_outside_the_limits_is_clamped_with_a_warning(): void
    {
        $this->set('600');

        $this->assertSame(60, $this->capture(fn () => EnvSetting::integer(self::VARIABLE, default: 10, min: 1, max: 60)));
        $this->assertStringContainsString(self::VARIABLE.'=600 liegt außerhalb des erlaubten Bereichs von 1 bis 60. Es gilt 60.', $this->warnings);
    }

    public function test_a_value_that_is_no_number_falls_back_to_the_default_with_a_warning(): void
    {
        $this->set('zehn');

        $this->assertSame(10, $this->capture(fn () => EnvSetting::integer(self::VARIABLE, default: 10, min: 1, max: 60)));
        $this->assertStringContainsString(self::VARIABLE.'=„zehn“ ist keine ganze Zahl. Es gilt die Vorgabe 10.', $this->warnings);
    }

    public function test_one_bad_entry_in_a_number_list_restores_the_whole_default(): void
    {
        $this->set('30,0');

        $this->assertSame([60, 600], $this->capture(fn () => EnvSetting::integerList(self::VARIABLE, default: [60, 600], min: 1, max: 86400)));
        $this->assertStringContainsString('„0“ ist keine ganze Zahl von 1 bis 86400. Es gilt die Vorgabe 60,600.', $this->warnings);
    }

    public function test_invalid_network_entries_are_dropped_with_a_warning(): void
    {
        $this->set('10.20.0.0/16, intern, 10.0.0.0/33, 192.168.1.0/');

        $this->assertSame(['10.20.0.0/16'], $this->capture(fn () => EnvSetting::networks(self::VARIABLE)));

        foreach (['„intern“', '„10.0.0.0/33“', '„192.168.1.0/“'] as $eintrag) {
            $this->assertStringContainsString(self::VARIABLE.': Der Eintrag '.$eintrag, $this->warnings);
        }
    }

    public function test_a_choice_list_keeps_order_and_drops_duplicates(): void
    {
        $erlaubt = ['location_email', 'owner_notification_email'];

        $this->assertSame($erlaubt, $this->capture(fn () => EnvSetting::choiceList(self::VARIABLE, default: $erlaubt, allowed: $erlaubt)));
        $this->assertSame('', $this->warnings);

        $this->set('owner_notification_email, location_email,owner_notification_email');
        $this->assertSame(['owner_notification_email', 'location_email'], $this->capture(fn () => EnvSetting::choiceList(self::VARIABLE, default: $erlaubt, allowed: $erlaubt)));
        $this->assertSame('', $this->warnings);

        $this->set('-');
        $this->assertSame([], $this->capture(fn () => EnvSetting::choiceList(self::VARIABLE, default: $erlaubt, allowed: $erlaubt)));
    }

    public function test_an_unknown_choice_restores_the_whole_default(): void
    {
        $erlaubt = ['location_email', 'owner_notification_email'];
        $this->set('owner_notification_email,support_email');

        $this->assertSame($erlaubt, $this->capture(fn () => EnvSetting::choiceList(self::VARIABLE, default: $erlaubt, allowed: $erlaubt)));
        $this->assertStringContainsString(self::VARIABLE.': Der Eintrag „support_email“ ist unbekannt. Es gilt die Vorgabe location_email,owner_notification_email.', $this->warnings);
    }

    public function test_a_hex_color_is_used_in_lower_case(): void
    {
        $this->set('#0D9488');

        $this->assertSame('#0d9488', $this->capture(fn () => EnvSetting::hexColor(self::VARIABLE, default: '#0f766e')));
        $this->assertSame('', $this->warnings);
    }

    public function test_an_invalid_hex_color_falls_back_to_the_default_with_a_warning(): void
    {
        $this->set('teal');

        $this->assertSame('#0f766e', $this->capture(fn () => EnvSetting::hexColor(self::VARIABLE, default: '#0f766e')));
        $this->assertStringContainsString(self::VARIABLE.'=„teal“ ist keine Farbe in der Schreibweise #rrggbb. Es gilt die Vorgabe #0f766e.', $this->warnings);
    }

    public function test_a_known_timezone_is_used(): void
    {
        $this->set('Europe/Vienna');

        $this->assertSame('Europe/Vienna', $this->capture(fn () => EnvSetting::timezone(self::VARIABLE, default: 'Europe/Berlin')));
        $this->assertSame('', $this->warnings);
    }

    public function test_an_unknown_timezone_falls_back_to_the_default_with_a_warning(): void
    {
        $this->set('Mond/Basis');

        $this->assertSame('Europe/Berlin', $this->capture(fn () => EnvSetting::timezone(self::VARIABLE, default: 'Europe/Berlin')));
        $this->assertStringContainsString(self::VARIABLE.'=„Mond/Basis“ ist keine bekannte Zeitzone. Es gilt die Vorgabe „Europe/Berlin“.', $this->warnings);
    }

    public function test_decimal_reads_point_and_comma_and_keeps_its_limits(): void
    {
        $this->assertSame(4.5, $this->capture(fn () => EnvSetting::decimal(self::VARIABLE, default: 4.5, min: 3.0, max: 7.0)));
        $this->assertSame('', $this->warnings);

        $this->set('6.2');
        $this->assertSame(6.2, $this->capture(fn () => EnvSetting::decimal(self::VARIABLE, default: 4.5, min: 3.0, max: 7.0)));
        $this->assertSame('', $this->warnings);

        $this->set('3,5');
        $this->assertSame(3.5, $this->capture(fn () => EnvSetting::decimal(self::VARIABLE, default: 4.5, min: 3.0, max: 7.0)));
        $this->assertSame('', $this->warnings);

        $this->set('9');
        $this->assertSame(7.0, $this->capture(fn () => EnvSetting::decimal(self::VARIABLE, default: 4.5, min: 3.0, max: 7.0)));
        $this->assertStringContainsString(self::VARIABLE, $this->warnings);

        $this->set('viel');
        $this->assertSame(4.5, $this->capture(fn () => EnvSetting::decimal(self::VARIABLE, default: 4.5, min: 3.0, max: 7.0)));
        $this->assertStringContainsString('keine Zahl', $this->warnings);
    }

    public function test_an_email_address_is_used_and_anything_else_falls_back_to_the_default_with_a_warning(): void
    {
        $this->assertSame('info@swayy.de', $this->capture(fn () => EnvSetting::email(self::VARIABLE, default: 'info@swayy.de')));
        $this->assertSame('', $this->warnings);

        $this->set(' Hilfe@Example.test ');
        $this->assertSame('Hilfe@Example.test', $this->capture(fn () => EnvSetting::email(self::VARIABLE, default: 'info@swayy.de')));
        $this->assertSame('', $this->warnings);

        foreach (['keine adresse', 'ohne-at.example.test', 'zwei@@example.test'] as $invalid) {
            $this->set($invalid);
            $this->assertSame('info@swayy.de', $this->capture(fn () => EnvSetting::email(self::VARIABLE, default: 'info@swayy.de')), $invalid);
            $this->assertStringContainsString(self::VARIABLE.'=', $this->warnings);
            $this->assertStringContainsString('keine E-Mail-Adresse', $this->warnings);
            $this->assertStringContainsString('Es gilt die Vorgabe „info@swayy.de“', $this->warnings);
        }
    }

    private function set(string $value): void
    {
        $_SERVER[self::VARIABLE] = $value;
        $_ENV[self::VARIABLE] = $value;
    }

    /**
     * Fuehrt den Aufruf aus und faengt dabei das Fehlerprotokoll in einer
     * eigenen Datei auf. PHPUnit setzt error_log fuer jeden Test selbst - der
     * eigene Wert gilt deshalb nur innerhalb dieses Aufrufs.
     */
    private function capture(callable $callback): mixed
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'swayy-setting');
        $previous = ini_set('error_log', $file);

        try {
            return $callback();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            $this->warnings = (string) file_get_contents($file);
            @unlink($file);
        }
    }
}

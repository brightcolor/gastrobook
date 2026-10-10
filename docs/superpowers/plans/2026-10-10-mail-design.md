# Mail-Design Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alle Mails von Swayy laufen durch einen gemeinsamen, hochwertigen HTML-Rahmen (Auftritt Betrieb oder Swayy) mit Tabelle, Knopf und Fußbereich, sauber für Spamfilter.

**Architecture:** Ein Parser (`TextBlocks`) zerlegt den fertigen Mailtext in Bausteine (Absatz, Angaben-Tabelle, Knopf, Trennlinie). Ein serialisierbares Wert-Objekt (`MailFrame`) trägt Auftritt, Überschrift, Markenfarbe, Logo-Pfad und Fußbereich; `MailFrameFactory` baut es aus Betrieb/Standort oder für Swayy. Ein Trait (`RendersInFrame`) gibt jedem Mailable denselben HTML-Rahmen und Textteil; `MailTheme` rechnet Farben samt Kontrastprüfung.

**Tech Stack:** Laravel 13 (Mailables, Blade), PHP 8.4, PHPUnit, Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-10-10-mail-design-design.md`

**Worktree:** `C:\Users\brigh\Claude Workingdir\gastrobook-mail-design`, Zweig `mail-design` (Basis `new-ui` 1dda878). Alle Pfade unten relativ dazu.

## Global Constraints

- Breite höchstens 600 px, Umbruch unter 520 px; Schriftstapel `-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif`; nur der Schriftzug „Swayy“ in `'Fraunces',Georgia,'Times New Roman',serif`.
- Nichts wird von außen geladen: keine Schriften, keine Bilder per URL, kein Zählpixel; Bilder nur eingebettet (`$message->embed`) mit `alt`, Breite und Höhe.
- Kein versteckter Text, kein `display:none`, kein JavaScript, keine Formulare, kein externes CSS; HTML-Teil unter 60 KB; `<html lang="de">`, `charset=utf-8`, `<title>`.
- Jede Mail ist mehrteilig (Text und HTML) mit gleichem Inhalt; der Textteil ist der normalisierte Text plus Fußbereich.
- Absender bleibt `MAIL_FROM_ADDRESS`; Anzeigename Betrieb (Gast-Mails) bzw. `MAIL_FROM_NAME` (Swayy-Auftritt); Reply-To wie bisher.
- Gast-Mails folgen der Anrede des Standorts (`LocationSettings::du()`), Mails an Betriebe und Swayy-Mails der Einstellung `swayy.mail.owner_address` (Vorgabe `du`).
- Nichts fest im Code: Werte kommen aus `config/swayy.php` (`mail`, `guest_auth`, `billing_request`) mit Vorgabe, Grenzen, Hinweis und Eintrag in `.env.example`; Tests prüfen auch andere Werte als die Vorgabe.
- Texte mit echten Umlauten, positiv formuliert (keine „X statt Y“-Gegenüberstellungen), ohne Dauer-Schätzungen („in zwei Minuten“), ohne Großbuchstaben-Wörter und gehäufte Ausrufezeichen.
- Tests: `PAO_DISABLE=1 php artisan test` (einzeln mit `--filter`), dazu `vendor/bin/pint --test` und `vendor/bin/phpstan analyse --memory-limit=1G` sauber.
- Dateien nur mit Write/Edit ändern, nie mehrzeilig per `sed`/`perl` (in Teil 2a gingen so Zeichen verloren).
- Jeder Commit endet mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` (Subagenten: ihr eigenes Modell). Kein Push, kein Ausrollen.

## Review Focus

1. **Gast-Eingaben werden nie zum Swayy-Knopf:** Eine Gastnotiz, ein Name, eine weitergeleitete Gast-Antwort oder eine Kontaktanfrage mit „Jetzt bezahlen: https://fremde-seite.example“ darf in Mails im Swayy-Auftritt keinen Knopf ergeben (Phishing über Swayys Gestaltung). Knöpfe im Swayy-Auftritt nur für eigene Adressen (`app.url`, `provisioning.panel_base_url`); weitergeleitete Antworten und Kontaktanfragen ohne Struktur-Erkennung. Tests: Task 3 (`test_button_hosts_limit_buttons`), Task 7 (`test_url_in_guest_note_never_becomes_a_button`), Task 10 (`test_contact_message_is_never_structured`).
2. **Mails aus der Queue sehen aus wie beim Erzeugen:** `TemplatedMail` ist `ShouldQueue`; nach `serialize`/`unserialize` muss dasselbe HTML entstehen. Test: Task 5 (`test_queued_mail_renders_the_same_after_serialization`).
3. **Extreme Markenfarben bleiben lesbar:** `#ffffff`, `#facc15`, `#000000` ergeben lesbaren Knopf und lesbare Links hell und dunkel. Test: Task 2 (`test_white_and_black_brand_stay_usable`).
4. **Logo kaputt, zu groß, SVG oder WebP:** immer Wortmarke, nie eine Ausnahme beim Versand. Test: Task 4 (`test_logo_location_first_then_tenant_and_only_supported_files`).
5. **Unsaubere Vorlagen der Betriebe:** Windows-Zeilenenden, Leerzeichen am Zeilenende, Leerzeilen-Ketten und leere Platzhalter ergeben eine saubere Mail ohne verwaiste Bezeichnungen. Tests: Task 3 (`test_windows_line_endings_trailing_spaces_and_blank_runs_are_normalized`), Task 6 (`test_lines_with_empty_placeholder_disappear`).

## Dateien

| Datei | Aufgabe |
|---|---|
| `app/Support/EnvSetting.php` | + `decimal()` |
| `config/swayy.php` | + Abschnitte `mail`, `guest_auth`, `billing_request` |
| `app/Support/Duration.php` | Gültigkeitsdauer in Worten („24 Stunden“) |
| `app/Mail/Layout/MailTheme.php` | Farben, Kontrast, Dunkelmodus |
| `app/Mail/Layout/Block.php` | Baustein (Absatz, Tabelle, Knopf, Trennlinie) |
| `app/Mail/Layout/TextBlocks.php` | Parser Text → Bausteine |
| `app/Mail/Layout/MailFrame.php` | Wert-Objekt des Rahmens |
| `app/Mail/Layout/MailFrameFactory.php` | Rahmen für Betrieb oder Swayy |
| `app/Mail/Concerns/RendersInFrame.php` | Content/Headers für Mailables |
| `lang/de/mails.php` | Überschriften, Gründe, Fußbereich-Texte |
| `resources/views/mail/layout/html.blade.php` | HTML-Rahmen |
| `resources/views/mail/layout/_block.blade.php` | ein Baustein in HTML |
| `resources/views/mail/layout/text.blade.php` | Textteil mit Fußbereich |
| `resources/images/mail/swayy@2x.png` | Swayy-Logo für Mails |
| `app/Console/Commands/MailPreview.php` | `mail:vorschau` |

---

### Task 1: Einstellungen für Mails

**Files:**
- Modify: `app/Support/EnvSetting.php` (neue Methode nach `integer()`)
- Modify: `config/swayy.php` (neue Abschnitte vor `'ui' => [`)
- Modify: `.env.example` (Block am Ende)
- Test: `tests/Unit/EnvSettingTest.php`, `tests/Feature/MailSettingsTest.php` (neu)

**Interfaces:**
- Produces: `EnvSetting::decimal(string $variable, float $default, float $min, float $max): float`; Konfiguration `swayy.mail.*`, `swayy.guest_auth.login_minutes|verify_minutes`, `swayy.billing_request.confirm_hours` (Schlüssel siehe Step 3).

- [ ] **Step 1: Failing tests schreiben**

In `tests/Unit/EnvSettingTest.php` vor der schließenden Klammer der Klasse ergänzen (die Helfer `set()` und `capture()` gibt es dort schon):

```php
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
```

`tests/Feature/MailSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    public function test_mail_settings_have_their_defaults(): void
    {
        $this->assertSame('du', config('swayy.mail.owner_address'));
        $this->assertTrue(config('swayy.mail.powered_by'));
        $this->assertSame(200, config('swayy.mail.logo_max_kb'));
        $this->assertSame(44, config('swayy.mail.logo_height'));
        $this->assertSame(4.5, config('swayy.mail.contrast_min'));
        $this->assertSame(2, config('swayy.mail.details_min_rows'));
        $this->assertSame(40, config('swayy.mail.label_max'));
        $this->assertSame(60, config('swayy.mail.button_label_max'));
        $this->assertSame(60, config('swayy.guest_auth.login_minutes'));
        $this->assertSame(1440, config('swayy.guest_auth.verify_minutes'));
        $this->assertSame(72, config('swayy.billing_request.confirm_hours'));
        $this->assertSame('#ffffff', config('swayy.mail.palette.on_brand'));
    }

    public function test_every_mail_variable_is_documented_in_env_example(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'SWAYY_MAIL_OWNER_ADDRESS', 'SWAYY_MAIL_POWERED_BY', 'SWAYY_MAIL_LOGO_MAX_KB', 'SWAYY_MAIL_LOGO_HEIGHT',
            'SWAYY_MAIL_CONTRAST_MIN', 'SWAYY_MAIL_DETAILS_MIN_ROWS', 'SWAYY_MAIL_LABEL_MAX', 'SWAYY_MAIL_BUTTON_LABEL_MAX',
            'SWAYY_GUEST_LOGIN_LINK_MINUTES', 'SWAYY_GUEST_VERIFY_LINK_MINUTES', 'SWAYY_BILLING_CONFIRM_HOURS',
        ] as $variable) {
            $this->assertStringContainsString($variable.'=', $example, $variable);
        }
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie schlagen fehl**

Run: `PAO_DISABLE=1 php artisan test --filter='EnvSettingTest|MailSettingsTest'`
Expected: FAIL (`Call to undefined method App\Support\EnvSetting::decimal()`, fehlende Konfiguration)

- [ ] **Step 3: Umsetzen**

In `app/Support/EnvSetting.php` direkt nach der Methode `integer()`:

```php
    /**
     * Dezimalzahl von $min bis $max, mit Punkt oder Komma geschrieben.
     */
    public static function decimal(string $variable, float $default, float $min, float $max): float
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }

        $value = filter_var(str_replace(',', '.', $raw), FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            self::warn("{$variable}=„{$raw}“ ist keine Zahl. Es gilt die Vorgabe {$default}. Bitte einen Wert von {$min} bis {$max} in der .env eintragen.");

            return $default;
        }

        if ($value < $min || $value > $max) {
            $limited = max($min, min($max, $value));
            self::warn("{$variable}={$raw} liegt außerhalb des erlaubten Bereichs von {$min} bis {$max}. Es gilt {$limited}. Bitte den Wert in der .env anpassen.");

            return $limited;
        }

        return $value;
    }
```

In `config/swayy.php` vor dem Kommentarblock „Oberfläche der Verwaltung“ (vor `'ui' => [`) einfügen:

```php
    /*
    |--------------------------------------------------------------------------
    | Gestaltung der Mails
    |--------------------------------------------------------------------------
    |
    | Alle Mails laufen durch einen gemeinsamen Rahmen (App\Mail\Layout).
    | owner_address: Anrede in Mails an Betriebe und in Swayy-Mails, „du“ oder
    | „Sie“; Gast-Mails folgen der Anrede des Standorts.
    | powered_by: „Reserviert mit Swayy“ im Fußbereich der Gast-Mails.
    | logo_max_kb: größte Logodatei, die eingebettet wird (16–1024); größere
    | Logos ergeben die Wortmarke. logo_height: Anzeigehöhe in px (24–96).
    | contrast_min: Mindestkontrast für Knopftext und Links (3,0–7,0); eine zu
    | helle Markenfarbe wird dafür abgedunkelt.
    | details_min_rows: Zeilen „Bezeichnung: Wert“, ab denen ein Absatz zur
    | Tabelle wird (1–6). label_max: längste Bezeichnung darin in Zeichen
    | (10–80). button_label_max: längste Knopfbeschriftung (20–120).
    | Ohne Umgebungsvariable: Farben, Tönung, Schriften und Maße des Rahmens.
    |
    */
    'mail' => [
        'owner_address' => EnvSetting::pattern('SWAYY_MAIL_OWNER_ADDRESS', default: 'du', regex: '/^(du|Sie)$/', hint: 'ist weder „du“ noch „Sie“'),
        'powered_by' => (bool) env('SWAYY_MAIL_POWERED_BY', true),
        'logo_max_kb' => EnvSetting::integer('SWAYY_MAIL_LOGO_MAX_KB', default: 200, min: 16, max: 1024),
        'logo_height' => EnvSetting::integer('SWAYY_MAIL_LOGO_HEIGHT', default: 44, min: 24, max: 96),
        'contrast_min' => EnvSetting::decimal('SWAYY_MAIL_CONTRAST_MIN', default: 4.5, min: 3.0, max: 7.0),
        'details_min_rows' => EnvSetting::integer('SWAYY_MAIL_DETAILS_MIN_ROWS', default: 2, min: 1, max: 6),
        'label_max' => EnvSetting::integer('SWAYY_MAIL_LABEL_MAX', default: 40, min: 10, max: 80),
        'button_label_max' => EnvSetting::integer('SWAYY_MAIL_BUTTON_LABEL_MAX', default: 60, min: 20, max: 120),
        'logo_types' => ['png', 'jpg', 'jpeg', 'gif'],
        'logo_max_width' => 220,
        'swayy_logo' => resource_path('images/mail/swayy@2x.png'),
        'swayy_logo_height' => 32,
        'width' => 600,
        'mobile_breakpoint' => 520,
        'font_stack' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif",
        'display_stack' => "'Fraunces',Georgia,'Times New Roman',serif",
        'tint' => 0.055,
        'line' => 0.16,
        'dark_tint' => 0.16,
        'dark_line' => 0.32,
        'palette' => [
            'page' => '#f5f5f4',
            'card' => '#ffffff',
            'plate' => '#ffffff',
            'border' => '#e7e5e4',
            'strong' => '#1c1917',
            'body' => '#44403c',
            'muted' => '#57534e',
            'faint' => '#78716c',
            'on_brand' => '#ffffff',
            'dark_page' => '#0c0a09',
            'dark_card' => '#1c1917',
            'dark_border' => '#292524',
            'dark_strong' => '#fafaf9',
            'dark_body' => '#d6d3d1',
            'dark_muted' => '#a8a29e',
        ],
    ],

    /*
    | Links für Gäste: Gültigkeit des Anmeldelinks (10–1440) und des
    | Bestätigungslinks einer Buchung (60–10080), jeweils in Minuten.
    */
    'guest_auth' => [
        'login_minutes' => EnvSetting::integer('SWAYY_GUEST_LOGIN_LINK_MINUTES', default: 60, min: 10, max: 1440),
        'verify_minutes' => EnvSetting::integer('SWAYY_GUEST_VERIFY_LINK_MINUTES', default: 1440, min: 60, max: 10080),
    ],

    /*
    | Tarifanfrage nach der Testphase: so viele Stunden gilt der
    | Bestätigungslink an die Kontaktadresse (1–336).
    */
    'billing_request' => [
        'confirm_hours' => EnvSetting::integer('SWAYY_BILLING_CONFIRM_HOURS', default: 72, min: 1, max: 336),
    ],
```

Am Ende von `.env.example` anhängen:

```
# Gestaltung der Mails (config/swayy.php › mail)
# Anrede in Mails an Betriebe und in Swayy-Mails: du oder Sie. Vorgabe du.
# SWAYY_MAIL_OWNER_ADDRESS=du
# „Reserviert mit Swayy“ im Fußbereich der Gast-Mails: true oder false. Vorgabe true.
# SWAYY_MAIL_POWERED_BY=true
# Größte Logodatei, die in eine Mail eingebettet wird, in KB (16–1024). Vorgabe 200.
# SWAYY_MAIL_LOGO_MAX_KB=200
# Anzeigehöhe des Logos in px (24–96). Vorgabe 44.
# SWAYY_MAIL_LOGO_HEIGHT=44
# Mindestkontrast für Knopftext und Links (3,0–7,0). Vorgabe 4.5.
# SWAYY_MAIL_CONTRAST_MIN=4.5
# Zeilen „Bezeichnung: Wert“, ab denen ein Absatz zur Tabelle wird (1–6). Vorgabe 2.
# SWAYY_MAIL_DETAILS_MIN_ROWS=2
# Längste Bezeichnung in einer solchen Zeile in Zeichen (10–80). Vorgabe 40.
# SWAYY_MAIL_LABEL_MAX=40
# Längste Knopfbeschriftung in Zeichen (20–120). Vorgabe 60.
# SWAYY_MAIL_BUTTON_LABEL_MAX=60
# Gültigkeit des Anmeldelinks für Gäste in Minuten (10–1440). Vorgabe 60.
# SWAYY_GUEST_LOGIN_LINK_MINUTES=60
# Gültigkeit des Bestätigungslinks einer Buchung in Minuten (60–10080). Vorgabe 1440.
# SWAYY_GUEST_VERIFY_LINK_MINUTES=1440
# Gültigkeit des Bestätigungslinks einer Tarifanfrage in Stunden (1–336). Vorgabe 72.
# SWAYY_BILLING_CONFIRM_HOURS=72
```

- [ ] **Step 4: Tests laufen lassen, sie bestehen**

Run: `PAO_DISABLE=1 php artisan test --filter='EnvSettingTest|MailSettingsTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Support/EnvSetting.php config/swayy.php .env.example tests/Unit/EnvSettingTest.php tests/Feature/MailSettingsTest.php
git commit -m "feat: Einstellungen für die Gestaltung der Mails

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: MailTheme (Farben und Kontrast)

**Files:**
- Create: `app/Mail/Layout/MailTheme.php`
- Test: `tests/Unit/MailThemeTest.php`

**Interfaces:**
- Consumes: `config('swayy.ui.default_brand_color')`, `config('swayy.mail.contrast_min|tint|line|dark_tint|dark_line|palette.*')` (Task 1)
- Produces: `MailTheme::fromBrand(?string $color): MailTheme`; Instanz-Methoden `button(): string`, `link(): string`, `darkLink(): string`, `tint(): string`, `line(): string`, `darkTint(): string`, `darkLine(): string`, `palette(string $key): string`; öffentliche Eigenschaft `string $brand`; statisch `contrast(string $a, string $b): float`, `mix(string $from, string $to, float $share): string`, `minimum(): float`.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/MailThemeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Layout\MailTheme;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MailThemeTest extends TestCase
{
    public function test_default_brand_keeps_its_color(): void
    {
        $theme = MailTheme::fromBrand(null);

        $this->assertSame('#0f766e', $theme->brand);
        $this->assertSame('#0f766e', $theme->button());
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'));
    }

    public function test_light_brand_is_darkened_until_white_text_is_readable(): void
    {
        $theme = MailTheme::fromBrand('#facc15');

        $this->assertNotSame('#facc15', $theme->button());
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->link(), '#ffffff'));
    }

    public function test_white_and_black_brand_stay_usable(): void
    {
        foreach (['#ffffff', '#000000', '#facc15'] as $color) {
            $theme = MailTheme::fromBrand($color);

            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->button(), '#ffffff'), $color);
            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->link(), '#ffffff'), $color);
            $this->assertGreaterThanOrEqual(4.5, MailTheme::contrast($theme->darkLink(), '#1c1917'), $color);
        }
    }

    public function test_invalid_color_falls_back_to_the_default_with_a_readable_log(): void
    {
        Log::spy();

        $this->assertSame('#0f766e', MailTheme::fromBrand('teal')->brand);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'teal') && str_contains($message, 'Einstellungen'),
        );
    }

    public function test_stricter_contrast_setting_darkens_further(): void
    {
        config(['swayy.mail.contrast_min' => 7.0]);

        $theme = MailTheme::fromBrand('#0f766e');

        $this->assertNotSame('#0f766e', $theme->button());
        $this->assertGreaterThanOrEqual(7.0, MailTheme::contrast($theme->button(), '#ffffff'));
    }

    public function test_tint_and_line_are_light_mixes_of_the_brand(): void
    {
        $theme = MailTheme::fromBrand('#0f766e');

        $this->assertSame(MailTheme::mix('#ffffff', '#0f766e', 0.055), $theme->tint());
        $this->assertSame(MailTheme::mix('#ffffff', '#0f766e', 0.16), $theme->line());
        $this->assertGreaterThan(12.0, MailTheme::contrast($theme->tint(), '#1c1917'));
        $this->assertSame(MailTheme::mix('#1c1917', '#0f766e', 0.16), $theme->darkTint());
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=MailThemeTest`
Expected: FAIL (`Class "App\Mail\Layout\MailTheme" not found`)

- [ ] **Step 3: Umsetzen**

`app/Mail/Layout/MailTheme.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Layout;

use Illuminate\Support\Facades\Log;

/**
 * Farben einer Mail aus der Markenfarbe.
 *
 * Knopf und Links halten den Mindestkontrast aus swayy.mail.contrast_min:
 * Eine zu helle Markenfarbe wird abgedunkelt, im Dunkelmodus die Linkfarbe
 * aufgehellt. Fläche und Haarlinien sind Mischungen mit dem Kartengrund.
 */
final class MailTheme
{
    private function __construct(public readonly string $brand) {}

    public static function fromBrand(?string $color): self
    {
        $default = strtolower((string) config('swayy.ui.default_brand_color', '#0f766e'));

        if ($color === null || trim($color) === '') {
            return new self($default);
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            Log::warning("Markenfarbe „{$color}“ ist keine Farbe in der Schreibweise #rrggbb. Die Mail nutzt die Vorgabe {$default}. Bitte die Markenfarbe unter Einstellungen › Allgemein neu wählen.");

            return new self($default);
        }

        return new self(strtolower($color));
    }

    /** Fläche des Knopfs; die Knopfschrift (palette.on_brand) erreicht darauf den Mindestkontrast. */
    public function button(): string
    {
        return self::untilContrast($this->brand, '#000000', $this->palette('on_brand'), self::minimum());
    }

    /** Linkfarbe auf der hellen Karte. */
    public function link(): string
    {
        return self::untilContrast($this->brand, '#000000', $this->palette('card'), self::minimum());
    }

    /** Linkfarbe auf der dunklen Karte. */
    public function darkLink(): string
    {
        return self::untilContrast($this->brand, '#ffffff', $this->palette('dark_card'), self::minimum());
    }

    public function tint(): string
    {
        return self::mix($this->palette('card'), $this->brand, (float) config('swayy.mail.tint'));
    }

    public function line(): string
    {
        return self::mix($this->palette('card'), $this->brand, (float) config('swayy.mail.line'));
    }

    public function darkTint(): string
    {
        return self::mix($this->palette('dark_card'), $this->brand, (float) config('swayy.mail.dark_tint'));
    }

    public function darkLine(): string
    {
        return self::mix($this->palette('dark_card'), $this->brand, (float) config('swayy.mail.dark_line'));
    }

    public function palette(string $key): string
    {
        return (string) config("swayy.mail.palette.{$key}");
    }

    public static function minimum(): float
    {
        return (float) config('swayy.mail.contrast_min', 4.5);
    }

    /**
     * Mischt $color in 20 Schritten mit $toward, bis sie gegen $against den
     * Kontrast $min erreicht. Reicht auch die volle Mischung nicht, gilt $toward.
     */
    private static function untilContrast(string $color, string $toward, string $against, float $min): string
    {
        for ($step = 0; $step <= 20; $step++) {
            $candidate = self::mix($color, $toward, $step / 20);
            if (self::contrast($candidate, $against) >= $min) {
                return $candidate;
            }
        }

        return $toward;
    }

    public static function mix(string $from, string $to, float $share): string
    {
        $a = self::rgb($from);
        $b = self::rgb($to);
        $out = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $share));
        }

        return $out;
    }

    /** Kontrastverhältnis nach WCAG 2.2. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $s = $channel / 255;

            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
```

- [ ] **Step 4: Test laufen lassen, er besteht**

Run: `PAO_DISABLE=1 php artisan test --filter=MailThemeTest`
Expected: PASS (6 Tests)

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Layout/MailTheme.php tests/Unit/MailThemeTest.php
git commit -m "feat: MailTheme – Farben der Mails mit Kontrastprüfung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Block und TextBlocks (Struktur aus dem Text)

**Files:**
- Create: `app/Mail/Layout/Block.php`, `app/Mail/Layout/TextBlocks.php`
- Test: `tests/Unit/TextBlocksTest.php`

**Interfaces:**
- Produces:
  - `Block` mit Konstanten `PARAGRAPH`, `DETAILS`, `BUTTON`, `DIVIDER`; Eigenschaften `string $type`, `string $text`, `list<array{label:string,value:string}> $rows`, `string $label`, `string $url`, `bool $primary`; Fabriken `Block::paragraph(string)`, `Block::details(array)`, `Block::button(string $label, string $url, bool $primary)`, `Block::divider()`.
  - `new TextBlocks(int $minRows, int $labelMax, int $buttonLabelMax, ?string $unsubscribeUrl = null, list<string> $buttonHosts = [])`; `TextBlocks::fromConfig(?string $unsubscribeUrl = null, array $buttonHosts = []): TextBlocks`; `parse(string $text): list<Block>`; statisch `TextBlocks::normalize(string $text): string`.
  - Leere `$buttonHosts` = jeder Host darf einen Knopf ergeben.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/TextBlocksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Layout\Block;
use App\Mail\Layout\TextBlocks;
use PHPUnit\Framework\TestCase;

class TextBlocksTest extends TestCase
{
    /**
     * @param  list<string>  $hosts
     * @return list<Block>
     */
    private function parse(string $text, int $minRows = 2, int $labelMax = 40, ?string $unsubscribe = null, array $hosts = []): array
    {
        return (new TextBlocks($minRows, $labelMax, 60, $unsubscribe, $hosts))->parse($text);
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<string>
     */
    private function types(array $blocks): array
    {
        return array_map(fn (Block $block) => $block->type, $blocks);
    }

    public function test_default_confirmation_becomes_paragraphs_table_button(): void
    {
        $blocks = $this->parse("Hallo Testgast,\n\nwir freuen uns auf dich.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nPersonen: 2\n\nReservierung ändern oder stornieren: https://swayy.test/r/abc\n\nBis bald!\nTrattoria Luna");

        $this->assertSame(['paragraph', 'paragraph', 'details', 'button', 'paragraph'], $this->types($blocks));
        $this->assertSame([
            ['label' => 'Datum', 'value' => '24.11.2026'],
            ['label' => 'Uhrzeit', 'value' => '18:00 Uhr'],
            ['label' => 'Personen', 'value' => '2'],
        ], $blocks[2]->rows);
        $this->assertSame('Reservierung ändern oder stornieren', $blocks[3]->label);
        $this->assertSame('https://swayy.test/r/abc', $blocks[3]->url);
        $this->assertTrue($blocks[3]->primary);
        $this->assertSame("Bis bald!\nTrattoria Luna", $blocks[4]->text);
    }

    public function test_alignment_spaces_and_colons_in_values_are_handled(): void
    {
        $blocks = $this->parse("Datum:    24.11.2026\nUhrzeit:  18:00 Uhr\nTisch:    7,  8");

        $this->assertSame([
            ['label' => 'Datum', 'value' => '24.11.2026'],
            ['label' => 'Uhrzeit', 'value' => '18:00 Uhr'],
            ['label' => 'Tisch', 'value' => '7, 8'],
        ], $blocks[0]->rows);
    }

    public function test_label_on_its_own_line_followed_by_a_link_becomes_a_button(): void
    {
        $blocks = $this->parse("Bitte bestätige innerhalb von 15 Minuten:\nhttps://swayy.test/w/1");

        $this->assertSame(['button'], $this->types($blocks));
        $this->assertSame('Bitte bestätige innerhalb von 15 Minuten', $blocks[0]->label);
        $this->assertSame('https://swayy.test/w/1', $blocks[0]->url);
    }

    public function test_link_lines_inside_a_details_block_follow_the_table(): void
    {
        $blocks = $this->parse("Datum: 24.11.2026\nUhrzeit: 18:00 Uhr\nStornieren: https://swayy.test/s\nTickets: 2");

        $this->assertSame(['details', 'button'], $this->types($blocks));
        $this->assertCount(3, $blocks[0]->rows);
    }

    public function test_only_the_first_button_is_primary(): void
    {
        $blocks = $this->parse("Jetzt bezahlen: https://swayy.test/p\n\nÄndern oder stornieren: https://swayy.test/m");

        $this->assertTrue($blocks[0]->primary);
        $this->assertFalse($blocks[1]->primary);
    }

    public function test_single_line_with_colon_stays_a_paragraph_unless_configured(): void
    {
        $this->assertSame(['paragraph'], $this->types($this->parse('Reservierungsnummer: R-CIHHL2')));
        $this->assertSame(['details'], $this->types($this->parse('Reservierungsnummer: R-CIHHL2', minRows: 1)));
        $this->assertSame(['paragraph'], $this->types($this->parse("Datum: 24.11.2026\nUhrzeit: 18:00 Uhr", minRows: 3)));
    }

    public function test_too_long_label_keeps_a_paragraph(): void
    {
        $this->assertSame(['details'], $this->types($this->parse("Reservierungsnummer: R-1\nPersonen: 2")));
        $this->assertSame(['paragraph'], $this->types($this->parse("Reservierungsnummer: R-1\nPersonen: 2", labelMax: 10)));
    }

    public function test_sentence_ending_with_colon_and_links_in_prose_stay_paragraphs(): void
    {
        $blocks = $this->parse("deine Reservierung ist bestätigt:\n\nMehr findest du auf https://trattoria.example/karte und vor Ort.");

        $this->assertSame(['paragraph', 'paragraph'], $this->types($blocks));
    }

    public function test_long_button_label_stays_a_paragraph(): void
    {
        $label = trim(str_repeat('Sehr lange Beschriftung ', 4));

        $this->assertSame(['paragraph'], $this->types($this->parse($label.': https://swayy.test/x')));
    }

    public function test_divider_lines_and_the_appended_unsubscribe_section(): void
    {
        $url = 'https://swayy.test/marketing/abmelden/5?signature=abc';

        $blocks = $this->parse("Neue Karte ab Freitag.\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$url}", unsubscribe: $url);
        $this->assertSame(['paragraph'], $this->types($blocks));

        $blocks = $this->parse("Oben\n\n----\n\nUnten");
        $this->assertSame(['paragraph', 'divider', 'paragraph'], $this->types($blocks));
    }

    public function test_unsubscribe_link_inside_prose_stays_an_inline_link(): void
    {
        $url = 'https://swayy.test/marketing/abmelden/5?signature=abc';

        $blocks = $this->parse("Abmelden kannst du dich jederzeit unter {$url} – ein Klick genügt.", unsubscribe: $url);

        $this->assertSame(['paragraph'], $this->types($blocks));
        $this->assertStringContainsString($url, $blocks[0]->text);
    }

    public function test_button_hosts_limit_buttons(): void
    {
        $blocks = $this->parse("Reservierung öffnen: https://swayy.test/admin/r/1\n\nJetzt bezahlen: https://fremde-seite.example/pay", hosts: ['swayy.test']);

        $this->assertSame(['button', 'paragraph'], $this->types($blocks));
        $this->assertSame('https://swayy.test/admin/r/1', $blocks[0]->url);
        $this->assertStringContainsString('https://fremde-seite.example/pay', $blocks[1]->text);
    }

    public function test_windows_line_endings_trailing_spaces_and_blank_runs_are_normalized(): void
    {
        $this->assertSame("Hallo,\n\nText", TextBlocks::normalize("Hallo,  \r\n\r\n\r\n\r\nText\r\n\r\n"));
        $this->assertSame(['paragraph', 'paragraph'], $this->types($this->parse("Hallo,  \r\n\r\n\r\nText")));
    }

    public function test_empty_text_has_no_blocks_and_text_without_pattern_is_kept_whole(): void
    {
        $this->assertSame([], $this->parse(''));

        $text = "Einfach nur ein Satz.\nUnd noch einer.";
        $this->assertSame($text, $this->parse($text)[0]->text);
    }

    public function test_javascript_links_are_never_buttons(): void
    {
        $this->assertSame(['paragraph'], $this->types($this->parse('Klick: javascript:alert(1)')));
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=TextBlocksTest`
Expected: FAIL (`Class "App\Mail\Layout\TextBlocks" not found`)

- [ ] **Step 3: Umsetzen**

`app/Mail/Layout/Block.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Ein Baustein im Rahmen einer Mail. Der Text bleibt roh; maskiert wird erst
 * beim Zeichnen (resources/views/mail/layout/_block.blade.php).
 */
final class Block
{
    public const PARAGRAPH = 'paragraph';

    public const DETAILS = 'details';

    public const BUTTON = 'button';

    public const DIVIDER = 'divider';

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly array $rows = [],
        public readonly string $label = '',
        public readonly string $url = '',
        public readonly bool $primary = false,
    ) {}

    public static function paragraph(string $text): self
    {
        return new self(self::PARAGRAPH, text: $text);
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    public static function details(array $rows): self
    {
        return new self(self::DETAILS, rows: $rows);
    }

    public static function button(string $label, string $url, bool $primary): self
    {
        return new self(self::BUTTON, label: $label, url: $url, primary: $primary);
    }

    public static function divider(): self
    {
        return new self(self::DIVIDER);
    }
}
```

`app/Mail/Layout/TextBlocks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Zerlegt den Text einer Mail in Bausteine (Spec 2026-10-10, „Struktur aus
 * dem Text“). Betriebe schreiben ihre Vorlagen als einfachen Text; erkannt
 * werden Angaben-Zeilen (Tabelle), Link-Zeilen (Knopf) und Trennlinien.
 * Alles andere bleibt Absatz, nichts geht verloren.
 */
final class TextBlocks
{
    private const LINK_ONLY = '~^https?://\S+$~i';

    private const LINK_LINE = '~^(.+?):\s*(https?://\S+)$~iu';

    private const DIVIDER = '/^\s*([-—=])\1{2,}\s*$/u';

    /**
     * @param  list<string>  $buttonHosts  leer: jeder Host darf einen Knopf ergeben
     */
    public function __construct(
        private readonly int $minRows,
        private readonly int $labelMax,
        private readonly int $buttonLabelMax,
        private readonly ?string $unsubscribeUrl = null,
        private readonly array $buttonHosts = [],
    ) {}

    /**
     * @param  list<string>  $buttonHosts
     */
    public static function fromConfig(?string $unsubscribeUrl = null, array $buttonHosts = []): self
    {
        return new self(
            (int) config('swayy.mail.details_min_rows', 2),
            (int) config('swayy.mail.label_max', 40),
            (int) config('swayy.mail.button_label_max', 60),
            $unsubscribeUrl,
            $buttonHosts,
        );
    }

    /**
     * Einheitliche Zeilenenden, keine Leerzeichen am Zeilenende, höchstens
     * eine Leerzeile am Stück, nichts Leeres am Ende.
     */
    public static function normalize(string $text): string
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = rtrim($line);
            if ($line === '' && ($out === [] || end($out) === '')) {
                continue;
            }
            $out[] = $line;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }

    /**
     * @return list<Block>
     */
    public function parse(string $text): array
    {
        $blocks = [];
        foreach ($this->chunks(self::normalize($text)) as $lines) {
            foreach ($this->blocksFor($lines) as $block) {
                $blocks[] = $block;
            }
        }

        return $this->finish($blocks);
    }

    /**
     * Zeilengruppen, getrennt an Leerzeilen; eine Trennlinie ist immer eine
     * eigene Gruppe.
     *
     * @return list<list<string>>
     */
    private function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = [];
        foreach (explode("\n", $text) as $line) {
            $divider = preg_match(self::DIVIDER, $line) === 1;
            if ($line === '' || $divider) {
                if ($current !== []) {
                    $chunks[] = $current;
                    $current = [];
                }
                if ($divider) {
                    $chunks[] = [$line];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $lines
     * @return list<Block>
     */
    private function blocksFor(array $lines): array
    {
        if (count($lines) === 1 && preg_match(self::DIVIDER, $lines[0]) === 1) {
            return [Block::divider()];
        }

        $buttons = [];
        $rest = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            $next = $i + 1 < $count ? trim($lines[$i + 1]) : null;

            if (preg_match(self::LINK_LINE, $line, $m) === 1 && $this->isButton($m[1], $m[2])) {
                if (! $this->isUnsubscribe($m[2])) {
                    $buttons[] = Block::button(trim($m[1]), $m[2], false);
                }

                continue;
            }

            if ($next !== null && str_ends_with($line, ':') && preg_match(self::LINK_ONLY, $next) === 1
                && $this->isButton(substr($line, 0, -1), $next)) {
                if (! $this->isUnsubscribe($next)) {
                    $buttons[] = Block::button(trim(substr($line, 0, -1)), $next, false);
                }
                $i++;

                continue;
            }

            if ($this->isUnsubscribeLine($line)) {
                continue;
            }

            $rest[] = $lines[$i];
        }

        $blocks = [];
        if ($rest !== []) {
            $rows = $this->rows($rest);
            $blocks[] = $rows !== null ? Block::details($rows) : Block::paragraph(implode("\n", $rest));
        }

        return [...$blocks, ...$buttons];
    }

    private function isButton(string $label, string $url): bool
    {
        $label = trim($label);
        $length = mb_strlen($label);
        if ($length < 2 || $length > $this->buttonLabelMax || preg_match('~https?://~i', $label) === 1) {
            return false;
        }
        if ($this->isUnsubscribe($url)) {
            return true;
        }
        if ($this->buttonHosts === []) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, $this->buttonHosts, true);
    }

    private function isUnsubscribe(string $url): bool
    {
        return $this->unsubscribeUrl !== null && $url === $this->unsubscribeUrl;
    }

    /** Eine Zeile, die auf den Abmeldelink endet, erscheint nur im Fußbereich (auch mit langer Beschriftung). */
    private function isUnsubscribeLine(string $line): bool
    {
        return $this->unsubscribeUrl !== null && str_ends_with($line, ': '.$this->unsubscribeUrl);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{label: string, value: string}>|null
     */
    private function rows(array $lines): ?array
    {
        if (count($lines) < $this->minRows) {
            return null;
        }

        $rows = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([^:]+?)\s*:\s+(\S.*?)\s*$/u', $line, $m) !== 1
                || mb_strlen($m[1]) > $this->labelMax
                || preg_match('~https?://~i', $m[2]) === 1) {
                return null;
            }
            $rows[] = ['label' => $m[1], 'value' => (string) preg_replace('/\s{2,}/u', ' ', $m[2])];
        }

        return $rows;
    }

    /**
     * Erster Knopf ist der Hauptknopf; Trennlinien am Anfang, am Ende und
     * doppelte Trennlinien entfallen.
     *
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private function finish(array $blocks): array
    {
        $out = [];
        $primaryGiven = false;
        foreach ($blocks as $block) {
            if ($block->type === Block::BUTTON) {
                $out[] = Block::button($block->label, $block->url, ! $primaryGiven);
                $primaryGiven = true;

                continue;
            }
            if ($block->type === Block::DIVIDER && ($out === [] || $out[count($out) - 1]->type === Block::DIVIDER)) {
                continue;
            }
            $out[] = $block;
        }
        while ($out !== [] && $out[count($out) - 1]->type === Block::DIVIDER) {
            array_pop($out);
        }

        return $out;
    }
}
```

- [ ] **Step 4: Test laufen lassen, er besteht**

Run: `PAO_DISABLE=1 php artisan test --filter=TextBlocksTest`
Expected: PASS (15 Tests)

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Layout/Block.php app/Mail/Layout/TextBlocks.php tests/Unit/TextBlocksTest.php
git commit -m "feat: TextBlocks – Tabelle, Knopf und Trennlinie aus dem Mailtext

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Überschriften, MailFrame und MailFrameFactory

**Files:**
- Create: `lang/de/mails.php`, `app/Mail/Layout/MailFrame.php`, `app/Mail/Layout/MailFrameFactory.php`
- Test: `tests/Feature/MailFrameTest.php`

**Interfaces:**
- Consumes: `MailTheme` (Task 2), Konfiguration (Task 1)
- Produces:
  - `MailFrame` (readonly): `look` (`MailFrame::LOOK_TENANT|LOOK_SWAYY`), `type`, `du`, `?headline`, `brandColor`, `wordmark`, `?logoPath`, `?logoWidth`, `?logoHeight`, `footer` (`array{name:string, street:?string, city:?string, phone:?string, email:?string, imprint:?string, privacy:?string, help:?string}`), `reason`, `?unsubscribeUrl`, `poweredBy`, `structured`; Methoden `theme(): MailTheme`, `isSwayy(): bool`.
  - `MailFrameFactory::forGuest(string $type, ?Tenant $tenant, ?Location $location, array $vars = [], ?string $unsubscribeUrl = null): MailFrame`
  - `MailFrameFactory::forSwayy(string $type, array $vars = [], bool $structured = true): MailFrame`
  - statisch `MailFrameFactory::ownerDu(): bool`, `MailFrameFactory::buttonHosts(): list<string>`
  - Platzhalter in Überschriften/Gründen: `:location`, `:tenant`, `:guest`, `:subject`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/MailFrameTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailFrameTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $tenant
     * @return array{0: Tenant, 1: Location}
     */
    private function luna(array $location = [], array $tenant = [], string $address = 'du'): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(array_merge([
            'name' => 'Luna GmbH', 'brand_primary_color' => null, 'brand_logo_path' => null,
            'imprint_url' => 'https://luna.example/impressum', 'privacy_url' => 'https://luna.example/datenschutz',
        ], $tenant));
        $setup['location']->update(array_merge([
            'name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115',
            'city' => 'Berlin', 'phone' => '030 1234567', 'email' => 'luna@example.test', 'brand_logo_path' => null,
        ], $location));
        $setup['location']->settings()->update(['guest_address' => $address]);
        $this->clearTenantContext();

        return [$setup['tenant']->fresh(), $setup['location']->fresh()];
    }

    private function frames(): MailFrameFactory
    {
        return app(MailFrameFactory::class);
    }

    public function test_guest_frame_uses_location_data_and_headline_in_du_and_sie(): void
    {
        [$tenant, $location] = $this->luna(address: 'du');
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);

        $this->assertSame(MailFrame::LOOK_TENANT, $frame->look);
        $this->assertTrue($frame->du);
        $this->assertSame('Deine Reservierung ist bestätigt', $frame->headline);
        $this->assertSame('Trattoria Luna', $frame->wordmark);
        $this->assertSame('Beispielstraße 12', $frame->footer['street']);
        $this->assertSame('10115 Berlin', $frame->footer['city']);
        $this->assertSame('030 1234567', $frame->footer['phone']);
        $this->assertSame('luna@example.test', $frame->footer['email']);
        $this->assertSame('https://luna.example/impressum', $frame->footer['imprint']);
        $this->assertSame('Du bekommst diese Mail, weil du bei Trattoria Luna reserviert hast.', $frame->reason);
        $this->assertTrue($frame->poweredBy);

        [$tenant, $location] = $this->luna(address: 'Sie');
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);
        $this->assertSame('Ihre Reservierung ist bestätigt', $frame->headline);
        $this->assertStringContainsString('reserviert haben', $frame->reason);
    }

    public function test_missing_or_unsafe_footer_data_is_left_out(): void
    {
        [$tenant, $location] = $this->luna(
            ['address_line1' => null, 'postal_code' => null, 'city' => null, 'phone' => null, 'email' => 'keine-adresse'],
            ['imprint_url' => 'javascript:alert(1)', 'privacy_url' => null],
        );
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);

        $this->assertNull($frame->footer['street']);
        $this->assertNull($frame->footer['city']);
        $this->assertNull($frame->footer['phone']);
        $this->assertNull($frame->footer['email']);
        $this->assertNull($frame->footer['imprint']);
        $this->assertNull($frame->footer['privacy']);
        $this->assertSame('Trattoria Luna', $frame->footer['name']);
    }

    public function test_brand_color_comes_from_the_tenant(): void
    {
        [$tenant, $location] = $this->luna(tenant: ['brand_primary_color' => '#b91c1c']);
        $this->assertSame('#b91c1c', $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->brandColor);

        [$tenant, $location] = $this->luna();
        $this->assertSame('#0f766e', $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->brandColor);
    }

    public function test_logo_location_first_then_tenant_and_only_supported_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/standort.png', UploadedFile::fake()->image('standort.png', 400, 100)->getContent());
        Storage::disk('public')->put('logos/1/betrieb.png', UploadedFile::fake()->image('betrieb.png', 100, 100)->getContent());
        Storage::disk('public')->put('logos/1/logo.webp', 'webp');
        Storage::disk('public')->put('logos/1/logo.svg', '<svg/>');
        Storage::disk('public')->put('logos/1/gross.png', str_repeat('x', 300 * 1024));

        [$tenant, $location] = $this->luna(['brand_logo_path' => 'logos/1/standort.png'], ['brand_logo_path' => 'logos/1/betrieb.png']);
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);
        $this->assertSame(Storage::disk('public')->path('logos/1/standort.png'), $frame->logoPath);
        $this->assertSame(44, $frame->logoHeight);
        $this->assertSame(176, $frame->logoWidth);

        [$tenant, $location] = $this->luna(['brand_logo_path' => null], ['brand_logo_path' => 'logos/1/betrieb.png']);
        $this->assertSame(Storage::disk('public')->path('logos/1/betrieb.png'), $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoPath);

        foreach (['logos/1/logo.webp', 'logos/1/logo.svg', 'logos/1/gross.png', 'logos/1/fehlt.png'] as $path) {
            [$tenant, $location] = $this->luna(['brand_logo_path' => $path]);
            $this->assertNull($this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoPath, $path);
        }

        config(['swayy.mail.logo_height' => 30]);
        [$tenant, $location] = $this->luna(['brand_logo_path' => 'logos/1/standort.png']);
        $this->assertSame(120, $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoWidth);
    }

    public function test_swayy_frame_follows_the_owner_address_setting(): void
    {
        config(['swayy.mail.owner_address' => 'du']);
        $frame = $this->frames()->forSwayy('swayy_trial_expiry');
        $this->assertSame(MailFrame::LOOK_SWAYY, $frame->look);
        $this->assertSame('Deine Testphase endet bald', $frame->headline);
        $this->assertSame(route('contact'), $frame->footer['help']);
        $this->assertSame(route('legal.imprint'), $frame->footer['imprint']);

        config(['swayy.mail.owner_address' => 'Sie']);
        $this->assertSame('Ihre Testphase endet bald', $this->frames()->forSwayy('swayy_trial_expiry')->headline);
    }

    public function test_unknown_type_has_no_headline_and_the_guest_reason(): void
    {
        [$tenant, $location] = $this->luna();
        $frame = $this->frames()->forGuest('etwas_neues', $tenant, $location);

        $this->assertNull($frame->headline);
        $this->assertStringContainsString('reserviert hast', $frame->reason);
    }

    public function test_operator_and_notice_texts_use_their_placeholders(): void
    {
        $frame = $this->frames()->forSwayy('operator_new_reservation', ['location' => 'Trattoria Luna']);
        $this->assertSame('Neue Reservierung für Trattoria Luna', $frame->headline);
        $this->assertStringContainsString('für Trattoria Luna Benachrichtigungen', $frame->reason);

        $this->assertSame('Lastschrift gekündigt – Luna GmbH', $this->frames()->forSwayy('swayy_notice', ['subject' => 'Lastschrift gekündigt – Luna GmbH'])->headline);
    }

    public function test_button_hosts_are_the_app_the_current_host_and_the_panel(): void
    {
        config(['app.url' => 'https://swayy.example', 'provisioning.panel_base_url' => 'https://panel.example']);
        $hosts = MailFrameFactory::buttonHosts();

        $this->assertContains('swayy.example', $hosts);
        $this->assertContains('panel.example', $hosts);
        $this->assertContains((string) parse_url(url('/'), PHP_URL_HOST), $hosts);
        $this->assertSame($hosts, array_values(array_unique($hosts)));
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=MailFrameTest`
Expected: FAIL (`Class "App\Mail\Layout\MailFrameFactory" not found`)

- [ ] **Step 3: Umsetzen**

`lang/de/mails.php`:

```php
<?php

// Überschriften und Gründe im Fußbereich je Mailart (Spec Mail-Design).
// Platzhalter: :location (Standort), :tenant (Betrieb), :guest (Gast),
// :subject (Betreff ohne „– Swayy“). Gast-Mails wählen nach der Anrede des
// Standorts, Mails an Betriebe und Swayy-Mails nach swayy.mail.owner_address.
// Fehlt eine Mailart unter „reasons“, gilt operator (operator_*), swayy
// (swayy_*) oder guest.

return [
    'headlines' => [
        'reservation_confirmed' => ['du' => 'Deine Reservierung ist bestätigt', 'Sie' => 'Ihre Reservierung ist bestätigt'],
        'reservation_requested' => ['du' => 'Deine Anfrage ist angekommen', 'Sie' => 'Ihre Anfrage ist angekommen'],
        'reservation_cancelled' => ['du' => 'Deine Reservierung ist storniert', 'Sie' => 'Ihre Reservierung ist storniert'],
        'reservation_rejected' => ['du' => 'Antwort auf deine Anfrage', 'Sie' => 'Antwort auf Ihre Anfrage'],
        'reservation_reminder' => ['du' => 'Erinnerung an deine Reservierung', 'Sie' => 'Erinnerung an Ihre Reservierung'],
        'reservation_table_changed' => ['du' => 'Dein Tisch hat sich geändert', 'Sie' => 'Ihr Tisch hat sich geändert'],
        'feedback_request' => ['du' => 'Wie war dein Besuch?', 'Sie' => 'Wie war Ihr Besuch?'],
        'waitlist_offer' => ['du' => 'Ein Tisch ist frei geworden', 'Sie' => 'Ein Tisch ist frei geworden'],
        'payment_pending' => ['du' => 'Bitte schließ deine Zahlung ab', 'Sie' => 'Bitte schließen Sie Ihre Zahlung ab'],
        'payment_reminder' => ['du' => 'Deine Zahlung ist noch offen', 'Sie' => 'Ihre Zahlung ist noch offen'],
        'payment_expired' => ['du' => 'Die Zahlungsfrist ist abgelaufen', 'Sie' => 'Die Zahlungsfrist ist abgelaufen'],
        'event_booking_confirmed' => ['du' => 'Deine Buchung ist bestätigt', 'Sie' => 'Ihre Buchung ist bestätigt'],
        'guest_verify' => ['du' => 'Bitte bestätige deine E-Mail-Adresse', 'Sie' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse'],
        'guest_login' => ['du' => 'Dein Anmeldelink', 'Sie' => 'Ihr Anmeldelink'],
        'operator_new_reservation' => ['du' => 'Neue Reservierung für :location', 'Sie' => 'Neue Reservierung für :location'],
        'operator_refund' => ['du' => 'Erstattung zu einer Reservierung', 'Sie' => 'Erstattung zu einer Reservierung'],
        'operator_guest_reply' => ['du' => 'Antwort von :guest', 'Sie' => 'Antwort von :guest'],
        'swayy_password_reset' => ['du' => 'Neues Passwort festlegen', 'Sie' => 'Neues Passwort festlegen'],
        'swayy_trial_expiry' => ['du' => 'Deine Testphase endet bald', 'Sie' => 'Ihre Testphase endet bald'],
        'swayy_billing_confirm' => ['du' => 'Bitte bestätige deine Anfrage', 'Sie' => 'Bitte bestätigen Sie Ihre Anfrage'],
        'swayy_billing_owner' => ['du' => 'Neue Tarifanfrage von :tenant', 'Sie' => 'Neue Tarifanfrage von :tenant'],
        'swayy_provisioned_welcome' => ['du' => 'Willkommen bei Swayy', 'Sie' => 'Willkommen bei Swayy'],
        'swayy_provisioned_approved' => ['du' => 'Dein Zugang ist freigeschaltet', 'Sie' => 'Ihr Zugang ist freigeschaltet'],
        'swayy_contact' => ['du' => 'Neue Kontaktanfrage', 'Sie' => 'Neue Kontaktanfrage'],
        'swayy_notice' => ['du' => ':subject', 'Sie' => ':subject'],
    ],

    'reasons' => [
        'guest' => ['du' => 'Du bekommst diese Mail, weil du bei :location reserviert hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie bei :location reserviert haben.'],
        'guest_verify' => ['du' => 'Du bekommst diese Mail, weil mit dieser Adresse bei :location reserviert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil mit dieser Adresse bei :location reserviert wurde.'],
        'guest_login' => ['du' => 'Du bekommst diese Mail, weil für dein Kundenkonto bei :location ein Anmeldelink angefordert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil für Ihr Kundenkonto bei :location ein Anmeldelink angefordert wurde.'],
        'waitlist_offer' => ['du' => 'Du bekommst diese Mail, weil du auf der Warteliste von :location stehst.', 'Sie' => 'Sie erhalten diese Mail, weil Sie auf der Warteliste von :location stehen.'],
        'event_booking_confirmed' => ['du' => 'Du bekommst diese Mail, weil du bei :location gebucht hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie bei :location gebucht haben.'],
        'marketing' => ['du' => 'Du bekommst diese Mail, weil du Neuigkeiten von :location abonniert hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie Neuigkeiten von :location abonniert haben.'],
        'operator' => ['du' => 'Du bekommst diese Mail, weil für :location Benachrichtigungen an diese Adresse gehen.', 'Sie' => 'Sie erhalten diese Mail, weil für :location Benachrichtigungen an diese Adresse gehen.'],
        'swayy' => ['du' => 'Du bekommst diese Mail, weil du ein Konto bei Swayy hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie ein Konto bei Swayy haben.'],
        'swayy_password_reset' => ['du' => 'Du bekommst diese Mail, weil für dein Swayy-Konto ein neues Passwort angefordert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil für Ihr Swayy-Konto ein neues Passwort angefordert wurde.'],
        'swayy_billing_owner' => ['du' => 'Interne Mail der Swayy-Plattform.', 'Sie' => 'Interne Mail der Swayy-Plattform.'],
        'swayy_contact' => ['du' => 'Interne Mail der Swayy-Plattform.', 'Sie' => 'Interne Mail der Swayy-Plattform.'],
    ],

    'footer' => [
        'imprint' => 'Impressum',
        'privacy' => 'Datenschutz',
        'help' => 'Hilfe & Kontakt',
        'phone' => 'Telefon',
        'email' => 'E-Mail',
        'powered_by' => 'Reserviert mit',
        'unsubscribe' => ['du' => 'Keine Neuigkeiten mehr?', 'Sie' => 'Keine Neuigkeiten mehr?'],
        'unsubscribe_link' => 'Abmelden',
    ],
];
```

`app/Mail/Layout/MailFrame.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Alles, was der Rahmen einer Mail braucht, ohne Modelle: nur Text, Farbe
 * und Pfade. So bleibt eine Mail in der Queue klein, und der Rahmen sieht
 * beim Versand so aus wie beim Erzeugen.
 */
final class MailFrame
{
    public const LOOK_TENANT = 'tenant';

    public const LOOK_SWAYY = 'swayy';

    /**
     * @param  array{name: string, street: ?string, city: ?string, phone: ?string, email: ?string, imprint: ?string, privacy: ?string, help: ?string}  $footer
     */
    public function __construct(
        public readonly string $look,
        public readonly string $type,
        public readonly bool $du,
        public readonly ?string $headline,
        public readonly string $brandColor,
        public readonly string $wordmark,
        public readonly ?string $logoPath,
        public readonly ?int $logoWidth,
        public readonly ?int $logoHeight,
        public readonly array $footer,
        public readonly string $reason,
        public readonly ?string $unsubscribeUrl = null,
        public readonly bool $poweredBy = false,
        public readonly bool $structured = true,
    ) {}

    public function theme(): MailTheme
    {
        return MailTheme::fromBrand($this->brandColor);
    }

    public function isSwayy(): bool
    {
        return $this->look === self::LOOK_SWAYY;
    }
}
```

`app/Mail/Layout/MailFrameFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Layout;

use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Baut den Rahmen einer Mail: im Auftritt des Betriebs (Gast-Mails) oder im
 * Auftritt von Swayy (Mails an Betriebe, Swayy-Mails).
 */
final class MailFrameFactory
{
    /**
     * @param  array<string, string>  $vars  weitere Platzhalter für Überschrift und Grund
     */
    public function forGuest(string $type, ?Tenant $tenant, ?Location $location, array $vars = [], ?string $unsubscribeUrl = null): MailFrame
    {
        $du = $location?->effectiveSettings()->du() ?? false;
        $name = trim((string) ($location?->name ?: ($tenant?->name ?: config('mail.from.name'))));
        $vars = ['location' => $name, 'tenant' => (string) ($tenant?->name ?: $name), 'guest' => '', 'subject' => '', ...$vars];
        [$logoPath, $logoWidth, $logoHeight] = $this->logo($location?->brand_logo_path ?: $tenant?->brand_logo_path, (int) config('swayy.mail.logo_height', 44));

        return new MailFrame(
            look: MailFrame::LOOK_TENANT,
            type: $type,
            du: $du,
            headline: $this->headline($type, $du, $vars),
            brandColor: (string) ($tenant?->brand_primary_color ?: config('swayy.ui.default_brand_color')),
            wordmark: $name,
            logoPath: $logoPath,
            logoWidth: $logoWidth,
            logoHeight: $logoHeight,
            footer: [
                'name' => $name,
                'street' => self::filled($location?->address_line1),
                'city' => self::filled(trim(($location?->postal_code ?? '').' '.($location?->city ?? ''))),
                'phone' => self::filled($location?->phone),
                'email' => self::email($location?->email),
                'imprint' => self::url($tenant?->imprint_url),
                'privacy' => self::url($tenant?->privacy_url),
                'help' => null,
            ],
            reason: $this->reason($type, $du, $vars),
            unsubscribeUrl: $unsubscribeUrl,
            poweredBy: (bool) config('swayy.mail.powered_by', true),
        );
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function forSwayy(string $type, array $vars = [], bool $structured = true): MailFrame
    {
        $du = self::ownerDu();
        $vars = ['location' => '', 'tenant' => '', 'guest' => '', 'subject' => '', ...$vars];
        [$logoPath, $logoWidth, $logoHeight] = $this->swayyLogo();

        return new MailFrame(
            look: MailFrame::LOOK_SWAYY,
            type: $type,
            du: $du,
            headline: $this->headline($type, $du, $vars),
            brandColor: (string) config('swayy.ui.default_brand_color'),
            wordmark: 'Swayy',
            logoPath: $logoPath,
            logoWidth: $logoWidth,
            logoHeight: $logoHeight,
            footer: [
                'name' => 'Swayy',
                'street' => null,
                'city' => null,
                'phone' => null,
                'email' => null,
                'imprint' => route('legal.imprint'),
                'privacy' => route('legal.privacy'),
                'help' => route('contact'),
            ],
            reason: $this->reason($type, $du, $vars),
            structured: $structured,
        );
    }

    public static function ownerDu(): bool
    {
        return config('swayy.mail.owner_address', 'du') === 'du';
    }

    /**
     * Hosts, auf die ein Knopf im Swayy-Auftritt zeigen darf.
     *
     * @return list<string>
     */
    public static function buttonHosts(): array
    {
        $hosts = [];
        // Eigene Adresse aus der Einstellung, die Adresse, unter der die
        // Anwendung gerade läuft (Links aus derselben Anfrage), und das Kundenpanel.
        foreach ([config('app.url'), url('/'), config('provisioning.panel_base_url')] as $url) {
            $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function headline(string $type, bool $du, array $vars): ?string
    {
        $key = "mails.headlines.{$type}.".($du ? 'du' : 'Sie');
        if (! trans()->has($key, 'de')) {
            return null;
        }
        $text = trim((string) __($key, $vars, 'de'));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function reason(string $type, bool $du, array $vars): string
    {
        $group = trans()->has("mails.reasons.{$type}", 'de') ? $type : match (true) {
            str_starts_with($type, 'operator_') => 'operator',
            str_starts_with($type, 'swayy_') => 'swayy',
            default => 'guest',
        };

        return (string) __("mails.reasons.{$group}.".($du ? 'du' : 'Sie'), $vars, 'de');
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function logo(?string $stored, int $height): array
    {
        if ($stored === null || $stored === '') {
            return [null, null, null];
        }

        $disk = Storage::disk('public');
        $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        if (! in_array($extension, (array) config('swayy.mail.logo_types'), true) || ! $disk->exists($stored)
            || $disk->size($stored) > (int) config('swayy.mail.logo_max_kb', 200) * 1024) {
            return [null, null, null];
        }

        return $this->measured($disk->path($stored), $height);
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function swayyLogo(): array
    {
        $path = (string) config('swayy.mail.swayy_logo');

        return is_file($path) ? $this->measured($path, (int) config('swayy.mail.swayy_logo_height', 32)) : [null, null, null];
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function measured(string $path, int $height): array
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return [null, null, null];
        }
        $width = min((int) config('swayy.mail.logo_max_width', 220), (int) round($height * $size[0] / $size[1]));

        return [$path, $width, $height];
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function email(?string $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('~^https?://\S+$~i', $value) === 1 ? $value : null;
    }
}
```

- [ ] **Step 4: Test laufen lassen, er besteht**

Run: `PAO_DISABLE=1 php artisan test --filter=MailFrameTest`
Expected: PASS (8 Tests). Hinweis: `test_swayy_frame_follows_the_owner_address_setting` braucht das Logo noch nicht; `logoPath` ist bis Task 5 `null`.

- [ ] **Step 5: Commit**

```bash
git add lang/de/mails.php app/Mail/Layout/MailFrame.php app/Mail/Layout/MailFrameFactory.php tests/Feature/MailFrameTest.php
git commit -m "feat: MailFrame – Rahmen für Betriebs- und Swayy-Mails mit Überschrift und Fußbereich

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: HTML-Rahmen, Textteil, Swayy-Logo und TemplatedMail

**Files:**
- Create: `app/Mail/Concerns/RendersInFrame.php`, `resources/views/mail/layout/html.blade.php`, `resources/views/mail/layout/_block.blade.php`, `resources/views/mail/layout/text.blade.php`, `resources/images/mail/swayy@2x.png`
- Modify: `app/Mail/TemplatedMail.php`
- Delete: `resources/views/mail/templated-html.blade.php` (`templated-text.blade.php` bleibt bis Task 7)
- Test: `tests/Feature/MailLayoutTest.php` (neu), `tests/Feature/TemplatedMailTest.php` (anpassen)

**Interfaces:**
- Consumes: `TextBlocks` (Task 3), `MailFrame`, `MailFrameFactory` (Task 4)
- Produces:
  - Trait `RendersInFrame` mit `framedContent(string $text, MailFrame $frame): Content` und `framedHeaders(MailFrame $frame): Headers`.
  - `TemplatedMail::__construct(string $mailSubject, string $mailBody, ?string $fromName = null, ?string $replyToAddress = null, ?MailFrame $frame = null)` und `resolvedFrame(): MailFrame` (ohne Rahmen: Swayy-Meldung `swayy_notice`, Überschrift = Betreff ohne „ – Swayy“).
  - View-Daten der Layout-Views: `$frame`, `$theme`, `$blocks`, `$plain`.

- [ ] **Step 1: Swayy-Logo erzeugen**

Eine Wegwerf-Datei `%TEMP%/swayy-logo.html` mit folgendem Inhalt anlegen; `{SVG}` durch den vollständigen Inhalt von `public/logo-mark.svg` ersetzen und `{FONT}` durch die `file:///`-Adresse von `node_modules/@fontsource-variable/fraunces/files/fraunces-latin-wght-normal.woff2` im Worktree:

```html
<!doctype html><html><head><meta charset="utf-8"><style>
@font-face{font-family:F;src:url('{FONT}') format('woff2');font-weight:100 900}
html,body{margin:0;background:#ffffff}
.l{display:inline-flex;align-items:center;gap:18px;padding:2px}
.l svg{width:64px;height:64px;display:block}
.l span{font-family:F;font-size:50px;font-weight:500;letter-spacing:-0.01em;color:#1c1917;line-height:1}
</style></head><body><div class="l">{SVG}<span>Swayy</span></div></body></html>
```

Rendern und zuschneiden (Bash, Pfade anpassen):

```bash
"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 --window-size=400,120 --screenshot="$TEMP/swayy-logo-roh.png" "file:///$(cygpath -m "$TEMP")/swayy-logo.html"
mkdir -p resources/images/mail
python -c "from PIL import Image, ImageChops; im=Image.open(r'$(cygpath -w "$TEMP")\swayy-logo-roh.png').convert('RGB'); bb=ImageChops.difference(im, Image.new('RGB', im.size, (255,255,255))).getbbox(); im.crop(bb).save('resources/images/mail/swayy@2x.png', optimize=True); print(im.crop(bb).size)"
```

Expected: Ausgabe etwa `(240, 64)`; die Datei ist kleiner als 15 KB. Bild ansehen: Zeichen mit Verlauf und „Swayy“ in Fraunces, weißer Grund.

- [ ] **Step 2: Failing tests schreiben**

`tests/Feature/MailLayoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\MailFrameFactory;
use App\Mail\TemplatedMail;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const BODY = "Hallo Frau Kessler,\n\nwir freuen uns auf Ihren Besuch.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\n\nReservierung ändern oder stornieren: https://swayy.test/r/abc\n\nBis bald!\nTrattoria Luna";

    /**
     * @param  array<string, mixed>  $location
     * @return array{0: Tenant, 1: Location}
     */
    private function luna(array $location = []): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['name' => 'Luna GmbH', 'imprint_url' => 'https://luna.example/impressum', 'privacy_url' => null, 'brand_logo_path' => null]);
        $setup['location']->update(array_merge(['name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115', 'city' => 'Berlin', 'phone' => '030 1234567', 'email' => 'luna@example.test', 'brand_logo_path' => null], $location));
        $setup['location']->settings()->update(['guest_address' => 'Sie']);
        $this->clearTenantContext();

        return [$setup['tenant']->fresh(), $setup['location']->fresh()];
    }

    private function guestMail(string $body = self::BODY, array $location = []): TemplatedMail
    {
        [$tenant, $location] = $this->luna($location);

        return new TemplatedMail('Reservierungsbestätigung', $body, 'Trattoria Luna', null,
            app(MailFrameFactory::class)->forGuest('reservation_confirmed', $tenant, $location));
    }

    public function test_guest_mail_shows_headline_table_button_and_footer(): void
    {
        $html = $this->guestMail()->render();

        $this->assertStringContainsString('Ihre Reservierung ist bestätigt</h1>', $html);
        $this->assertStringContainsString('>Datum</td>', $html);
        $this->assertStringContainsString('>24.11.2026</td>', $html);
        $this->assertStringContainsString('href="https://swayy.test/r/abc"', $html);
        $this->assertStringContainsString('>Reservierung ändern oder stornieren</a>', $html);
        $this->assertStringContainsString('Beispielstraße 12', $html);
        $this->assertStringContainsString('href="tel:0301234567"', $html);
        $this->assertStringContainsString('href="mailto:luna@example.test"', $html);
        $this->assertStringContainsString('href="https://luna.example/impressum"', $html);
        $this->assertStringContainsString('weil Sie bei Trattoria Luna reserviert haben', $html);
        $this->assertStringContainsString('Reserviert mit', $html);
    }

    public function test_html_loads_nothing_from_outside_and_hides_nothing(): void
    {
        $html = $this->guestMail()->render();

        $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('display:none', str_replace(' ', '', $html));
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertLessThan(60 * 1024, strlen($html));
    }

    public function test_text_from_guests_or_tenants_is_escaped_and_only_web_links_are_links(): void
    {
        $html = $this->guestMail("Hallo <b>Gast</b>,\n\nKlick: javascript:alert(1)\n\nName: <img src=x>\nNotiz: \"zitiert\"")->render();

        $this->assertStringContainsString('&lt;b&gt;Gast&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;img src=x&gt;', $html);
    }

    public function test_text_part_carries_the_text_and_the_footer(): void
    {
        $mail = $this->guestMail();

        $mail->assertSeeInText('Reservierung ändern oder stornieren: https://swayy.test/r/abc');
        $mail->assertSeeInText('Beispielstraße 12 · 10115 Berlin');
        $mail->assertSeeInText('Impressum: https://luna.example/impressum');
        $mail->assertSeeInText('weil Sie bei Trattoria Luna reserviert haben');
        $mail->assertDontSeeInText('<td');
    }

    public function test_queued_mail_renders_the_same_after_serialization(): void
    {
        $mail = $this->guestMail();
        $copy = unserialize(serialize($mail));

        $this->assertSame($mail->render(), $copy->render());
    }

    public function test_mail_without_frame_is_a_swayy_notice_with_the_subject_as_headline(): void
    {
        $html = (new TemplatedMail('Lastschrift gekündigt – Luna GmbH', "Hallo,\n\ndas Mandat ist beendet."))->render();

        $this->assertStringContainsString('Lastschrift gekündigt – Luna GmbH</h1>', $html);
        $this->assertStringContainsString('Hilfe &amp; Kontakt', $html);
        $this->assertStringContainsString('alt="Swayy"', $html);
        $this->assertStringNotContainsString('Reserviert mit', $html);
    }

    public function test_logo_is_embedded_and_an_svg_falls_back_to_the_wordmark(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', UploadedFile::fake()->image('logo.png', 200, 100)->getContent());

        $html = $this->guestMail(location: ['brand_logo_path' => 'logos/1/logo.png'])->render();
        $this->assertMatchesRegularExpression('/<img src="(cid:|data:image\/png)[^"]*" alt="Trattoria Luna" width="88" height="44"/', $html);

        Storage::disk('public')->put('logos/1/logo.svg', '<svg/>');
        $html = $this->guestMail(location: ['brand_logo_path' => 'logos/1/logo.svg'])->render();
        $this->assertStringNotContainsString('alt="Trattoria Luna"', $html);
        $this->assertStringContainsString('letter-spacing:-0.015em', $html);
    }

    public function test_dark_mode_and_mobile_rules_are_present(): void
    {
        $html = $this->guestMail()->render();

        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-ogsc]', $html);
        $this->assertStringContainsString('@media (max-width: 520px)', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
    }

    public function test_paragraphs_below_a_divider_are_set_as_fine_print(): void
    {
        $html = $this->guestMail("Bitte bestätigen.\n\n---\n\nDer Link gilt 1 Stunde.")->render();

        $this->assertMatchesRegularExpression('/font-size:13px[^>]*>Der Link gilt 1 Stunde\./', $html);
    }

    public function test_light_brand_colors_get_a_readable_button(): void
    {
        [$tenant, $location] = $this->luna();
        $tenant->update(['brand_primary_color' => '#facc15']);
        $mail = new TemplatedMail('Betreff', self::BODY, null, null, app(MailFrameFactory::class)->forGuest('reservation_confirmed', $tenant->fresh(), $location));

        $this->assertStringNotContainsString('background:#facc15', $mail->render());
    }
}
```

In `tests/Feature/TemplatedMailTest.php` den dritten Test ersetzen:

```php
    public function test_sends_multipart_html_with_clickable_links_and_escaped_body(): void
    {
        $mail = new TemplatedMail('Betreff', "Hallo <b>Gast</b>\nLink: https://example.test/manage/abc", 'Restaurant Sonne');

        // Ohne eigenen Rahmen: Swayy-Meldung. Der Link zeigt auf einen fremden Host,
        // bleibt also anklickbarer Link im Text; Eingaben bleiben maskiert.
        $mail->assertSeeInHtml('alt="Swayy"', false);
        $mail->assertSeeInHtml('<a href="https://example.test/manage/abc"', false);
        $mail->assertSeeInHtml('&lt;b&gt;Gast&lt;/b&gt;', false);
        $mail->assertSeeInText('Link: https://example.test/manage/abc');
    }
```

- [ ] **Step 3: Tests laufen lassen, sie schlagen fehl**

Run: `PAO_DISABLE=1 php artisan test --filter='MailLayoutTest|TemplatedMailTest'`
Expected: FAIL (View `mail.layout.html` fehlt, `TemplatedMail` hat keinen fünften Parameter)

- [ ] **Step 4: Trait schreiben**

`app/Mail/Concerns/RendersInFrame.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Mail\Layout\Block;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Mail\Layout\TextBlocks;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * Text einer Mail im gemeinsamen Rahmen: HTML aus den erkannten Bausteinen,
 * Textteil aus dem normalisierten Text plus Fußbereich.
 */
trait RendersInFrame
{
    protected function framedContent(string $text, MailFrame $frame): Content
    {
        $plain = TextBlocks::normalize($text);

        if (! $frame->structured) {
            // Fremder Text (Gast-Antwort, Kontaktformular): nur Absätze, nie Knöpfe.
            $blocks = $plain === '' ? [] : array_map(
                static fn (string $paragraph) => Block::paragraph($paragraph),
                explode("\n\n", $plain),
            );
        } else {
            $blocks = TextBlocks::fromConfig(
                $frame->unsubscribeUrl,
                $frame->isSwayy() ? MailFrameFactory::buttonHosts() : [],
            )->parse($plain);
        }

        return new Content(
            html: 'mail.layout.html',
            text: 'mail.layout.text',
            with: [
                'frame' => $frame,
                'theme' => $frame->theme(),
                'blocks' => $blocks,
                'plain' => $plain,
            ],
        );
    }

    protected function framedHeaders(MailFrame $frame): Headers
    {
        if ($frame->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$frame->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
```

- [ ] **Step 5: Views schreiben**

`resources/views/mail/layout/html.blade.php`:

```blade
{{-- Gemeinsamer HTML-Rahmen aller Mails (Spec 2026-10-10 Mail-Design). --}}
@php
    $font = (string) config('swayy.mail.font_stack');
    $display = (string) config('swayy.mail.display_stack');
    $width = (int) config('swayy.mail.width');
    $breakpoint = (int) config('swayy.mail.mobile_breakpoint');
    $f = $frame->footer;
    $tone = $frame->du ? 'du' : 'Sie';
    $sep = '<span class="t-muted" style="color:'.e($theme->palette('faint')).';padding:0 6px;">·</span>';
    $footerLink = 'class="t-muted" style="color:'.e($theme->palette('muted')).';text-decoration:underline;"';
    $address = implode($sep, array_map('e', array_filter([$f['street'], $f['city']])));
    $contact = implode($sep, array_filter([
        $f['phone'] ? '<a href="tel:'.e(preg_replace('/[^+0-9]/', '', $f['phone'])).'" '.$footerLink.'>'.e($f['phone']).'</a>' : null,
        $f['email'] ? '<a href="mailto:'.e($f['email']).'" '.$footerLink.'>'.e($f['email']).'</a>' : null,
    ]));
    $legal = implode($sep, array_filter([
        $f['help'] ? '<a href="'.e($f['help']).'" '.$footerLink.'>'.e(__('mails.footer.help', [], 'de')).'</a>' : null,
        $f['imprint'] ? '<a href="'.e($f['imprint']).'" '.$footerLink.'>'.e(__('mails.footer.imprint', [], 'de')).'</a>' : null,
        $f['privacy'] ? '<a href="'.e($f['privacy']).'" '.$footerLink.'>'.e(__('mails.footer.privacy', [], 'de')).'</a>' : null,
    ]));
    $finePrint = false;
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $frame->headline ?? $frame->wordmark }}</title>
<style>
  body { margin:0; padding:0; -webkit-text-size-adjust:100%; }
  a { text-underline-offset:3px; }
  @media (max-width: {{ $breakpoint }}px) {
    .px { padding-left:24px !important; padding-right:24px !important; }
    .h1 { font-size:23px !important; line-height:30px !important; }
    .btn { width:100% !important; }
    .btn a { display:block !important; text-align:center !important; }
  }
  @media (prefers-color-scheme: dark) {
    .bg-page { background:{{ $theme->palette('dark_page') }} !important; }
    .card { background:{{ $theme->palette('dark_card') }} !important; border-color:{{ $theme->palette('dark_border') }} !important; }
    .t-strong { color:{{ $theme->palette('dark_strong') }} !important; }
    .t-body { color:{{ $theme->palette('dark_body') }} !important; }
    .t-muted { color:{{ $theme->palette('dark_muted') }} !important; }
    .t-link { color:{{ $theme->darkLink() }} !important; }
    .panel { background:{{ $theme->darkTint() }} !important; }
    .panel-row td { border-color:{{ $theme->darkLine() }} !important; }
    .rule { border-color:{{ $theme->palette('dark_border') }} !important; }
  }
  [data-ogsc] .bg-page { background:{{ $theme->palette('dark_page') }} !important; }
  [data-ogsc] .card { background:{{ $theme->palette('dark_card') }} !important; border-color:{{ $theme->palette('dark_border') }} !important; }
  [data-ogsc] .t-strong { color:{{ $theme->palette('dark_strong') }} !important; }
  [data-ogsc] .t-body { color:{{ $theme->palette('dark_body') }} !important; }
  [data-ogsc] .t-muted { color:{{ $theme->palette('dark_muted') }} !important; }
  [data-ogsc] .t-link { color:{{ $theme->darkLink() }} !important; }
  [data-ogsc] .panel { background:{{ $theme->darkTint() }} !important; }
</style>
</head>
<body class="bg-page" style="margin:0;padding:0;background:{{ $theme->palette('page') }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-page" style="background:{{ $theme->palette('page') }};">
<tr><td align="center" style="padding:0 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:{{ $width }}px;">
  <tr><td align="center" style="padding:40px 24px 22px;">
    @if ($frame->logoPath)
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:{{ $theme->palette('plate') }};border-radius:10px;padding:8px 12px;">
        <img src="{{ $message->embed($frame->logoPath) }}" alt="{{ $frame->wordmark }}" width="{{ $frame->logoWidth }}" height="{{ $frame->logoHeight }}" style="display:block;width:{{ $frame->logoWidth }}px;height:{{ $frame->logoHeight }}px;border:0;">
      </td></tr></table>
    @else
      <div class="t-strong" style="font-family:{{ $frame->isSwayy() ? $display : $font }};font-size:19px;line-height:24px;font-weight:600;letter-spacing:-0.015em;color:{{ $theme->palette('strong') }};">{{ $frame->wordmark }}</div>
    @endif
  </td></tr>
  <tr><td>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="card" style="background:{{ $theme->palette('card') }};border:1px solid {{ $theme->palette('border') }};border-radius:16px;">
      <tr><td class="px" style="padding:40px 44px 36px;">
        @if ($frame->headline)
          <h1 class="h1 t-strong" style="margin:0 0 14px;font-family:{{ $font }};font-size:26px;line-height:33px;font-weight:600;letter-spacing:-0.022em;color:{{ $theme->palette('strong') }};">{{ $frame->headline }}</h1>
        @endif
        @foreach ($blocks as $block)
          @if ($block->type === \App\Mail\Layout\Block::DIVIDER)
            @php $finePrint = true; @endphp
          @endif
          @include('mail.layout._block', ['block' => $block, 'theme' => $theme, 'font' => $font, 'finePrint' => $finePrint])
        @endforeach
      </td></tr>
    </table>
  </td></tr>
  <tr><td align="center" class="t-muted" style="padding:28px 32px 44px;font-family:{{ $font }};font-size:13px;line-height:21px;color:{{ $theme->palette('muted') }};">
    @if ($frame->isSwayy())
      <div class="t-strong" style="font-family:{{ $display }};font-size:15px;font-weight:500;color:{{ $theme->palette('body') }};">Swayy</div>
    @else
      <div class="t-strong" style="font-weight:600;color:{{ $theme->palette('body') }};">{{ $f['name'] }}</div>
      @if ($address !== '')<div>{!! $address !!}</div>@endif
      @if ($contact !== '')<div>{!! $contact !!}</div>@endif
    @endif
    @if ($legal !== '')<div style="padding-top:10px;">{!! $legal !!}</div>@endif
    <div style="padding-top:18px;">{{ $frame->reason }}</div>
    @if ($frame->unsubscribeUrl)
      <div style="padding-top:8px;">{{ __('mails.footer.unsubscribe.'.$tone, [], 'de') }} <a href="{{ $frame->unsubscribeUrl }}" {!! $footerLink !!}>{{ __('mails.footer.unsubscribe_link', [], 'de') }}</a></div>
    @endif
    @if (! $frame->isSwayy() && $frame->poweredBy)
      <div style="padding-top:18px;">{{ __('mails.footer.powered_by', [], 'de') }} <a href="{{ route('home') }}" class="t-muted" style="color:{{ $theme->palette('muted') }};text-decoration:none;font-family:{{ $display }};font-size:14px;font-weight:500;">Swayy</a></div>
    @endif
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
```

`resources/views/mail/layout/_block.blade.php`:

```blade
@php
    $muted = $theme->palette('muted');
@endphp
@switch($block->type)
    @case(\App\Mail\Layout\Block::PARAGRAPH)
        @php
            $html = nl2br((string) preg_replace(
                '~(https?://[^\s<]+)~i',
                '<a href="$1" class="t-link" style="color:'.e($theme->link()).';word-break:break-all;">$1</a>',
                e($block->text),
            ));
        @endphp
        @if ($finePrint)
            <p class="t-muted" style="margin:0 0 6px;font-family:{{ $font }};font-size:13px;line-height:20px;color:{{ $muted }};">{!! $html !!}</p>
        @else
            <p class="t-body" style="margin:0 0 16px;font-family:{{ $font }};font-size:16px;line-height:26px;color:{{ $theme->palette('body') }};">{!! $html !!}</p>
        @endif
        @break

    @case(\App\Mail\Layout\Block::DETAILS)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="panel" style="background:{{ $theme->tint() }};border-radius:12px;margin:8px 0 24px;">
            <tr><td style="padding:6px 22px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    @foreach ($block->rows as $row)
                        @php $border = $loop->last ? '' : 'border-bottom:1px solid '.$theme->line().';'; @endphp
                        <tr class="panel-row">
                            <td class="t-muted" style="padding:13px 0;{{ $border }}font-family:{{ $font }};font-size:14px;line-height:20px;color:{{ $muted }};vertical-align:top;">{{ $row['label'] }}</td>
                            <td class="t-strong" align="right" style="padding:13px 0 13px 16px;{{ $border }}font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->palette('strong') }};vertical-align:top;word-break:break-word;">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        </table>
        @break

    @case(\App\Mail\Layout\Block::BUTTON)
        @if ($block->primary)
            <table role="presentation" cellpadding="0" cellspacing="0" class="btn" style="margin:4px 0 24px;"><tr><td style="border-radius:6px;background:{{ $theme->button() }};">
                <a href="{{ $block->url }}" style="display:inline-block;padding:14px 26px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->palette('on_brand') }};text-decoration:none;border-radius:6px;">{{ $block->label }}</a>
            </td></tr></table>
        @else
            <table role="presentation" cellpadding="0" cellspacing="0" class="btn" style="margin:0 0 24px;"><tr><td style="border-radius:6px;border:1px solid {{ $theme->line() }};">
                <a href="{{ $block->url }}" class="t-link" style="display:inline-block;padding:12px 22px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->link() }};text-decoration:none;border-radius:6px;">{{ $block->label }}</a>
            </td></tr></table>
        @endif
        @break

    @case(\App\Mail\Layout\Block::DIVIDER)
        <div class="rule" style="border-top:1px solid {{ $theme->palette('border') }};margin:8px 0 20px;line-height:0;font-size:0;">&nbsp;</div>
        @break
@endswitch
```

`resources/views/mail/layout/text.blade.php` (reiner Text: `{!! !!}`, weil nichts als HTML gelesen wird):

```blade
@php
    $f = $frame->footer;
    $lines = array_values(array_filter([
        implode(' · ', array_filter([$f['street'], $f['city']])),
        implode(' · ', array_filter([$f['phone'], $f['email']])),
        $f['help'] ? __('mails.footer.help', [], 'de').': '.$f['help'] : '',
        $f['imprint'] ? __('mails.footer.imprint', [], 'de').': '.$f['imprint'] : '',
        $f['privacy'] ? __('mails.footer.privacy', [], 'de').': '.$f['privacy'] : '',
    ]));
@endphp
{!! $plain !!}

--
{!! $f['name'] !!}
@foreach ($lines as $line)
{!! $line !!}
@endforeach

{!! $frame->reason !!}
@if (! $frame->isSwayy() && $frame->poweredBy)
{!! __('mails.footer.powered_by', [], 'de') !!} Swayy
@endif
```

- [ ] **Step 6: TemplatedMail umstellen**

`app/Mail/TemplatedMail.php` vollständig ersetzen:

```php
<?php

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly ?string $fromName = null,
        public readonly ?string $replyToAddress = null,
        public readonly ?MailFrame $frame = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Keep the authenticated MAIL_FROM_ADDRESS (so SPF/DKIM stay aligned),
            // but use the tenant's name as the display name instead of the generic
            // global name — a generic/mismatched sender is a spam signal.
            from: new Address(
                config('mail.from.address'),
                $this->fromName ?: config('mail.from.name'),
            ),
            subject: $this->mailSubject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return $this->framedContent($this->mailBody, $this->resolvedFrame());
    }

    public function headers(): Headers
    {
        return $this->framedHeaders($this->resolvedFrame());
    }

    /**
     * Ohne eigenen Rahmen ist die Mail eine Meldung von Swayy (etwa zur
     * Lastschrift); die Überschrift ist der Betreff ohne „ – Swayy“.
     */
    public function resolvedFrame(): MailFrame
    {
        return $this->frame ?? app(MailFrameFactory::class)->forSwayy('swayy_notice', [
            'subject' => (string) preg_replace('/\s+–\s+Swayy$/u', '', $this->mailSubject),
        ]);
    }
}
```

`resources/views/mail/templated-html.blade.php` löschen: `git rm resources/views/mail/templated-html.blade.php`

- [ ] **Step 7: Tests laufen lassen, sie bestehen**

Run: `PAO_DISABLE=1 php artisan test --filter='MailLayoutTest|TemplatedMailTest|MailFrameTest'`
Expected: PASS. Schlägt `test_logo_is_embedded…` am `width="88"` fehl, die Rechnung prüfen: 200×100 bei Höhe 44 ergibt 88.

- [ ] **Step 8: Gesamte Suite, Pint, PHPStan**

Run: `PAO_DISABLE=1 php artisan test` → alle grün außer Tests, die alte Mailtexte prüfen (gibt es nach der Bestandsaufnahme nur in `TemplatedMailTest`, oben angepasst). `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G` sauber.

- [ ] **Step 9: Commit**

```bash
git add app/Mail/Concerns/RendersInFrame.php app/Mail/TemplatedMail.php resources/views/mail/layout resources/images/mail/swayy@2x.png tests/Feature/MailLayoutTest.php tests/Feature/TemplatedMailTest.php
git commit -m "feat: gemeinsamer HTML-Rahmen für Mails (Tabelle, Knopf, Fußbereich, Dunkelmodus)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Gast-Mails anschließen (Vorlagen, Tischänderung, Warteliste, Events)

**Files:**
- Modify: `app/Services/GuestMailSender.php` (`toGuest`)
- Modify: `app/Services/NotificationTemplateRenderer.php` (`render`, neue `dropEmptyLines`, `defaults`)
- Modify: `app/Services/ReservationLifecycleService.php` (`sendGuestMail`, Tischänderung um Zeile 526–548)
- Modify: `app/Services/WaitlistService.php` (Mail um Zeile 210–233)
- Modify: `app/Services/EventBookingService.php` (`sendConfirmationMail`)
- Test: `tests/Feature/GuestMailDesignTest.php` (neu)

**Interfaces:**
- Consumes: `MailFrameFactory::forGuest` (Task 4), `TemplatedMail` mit Rahmen (Task 5)
- Produces: `GuestMailSender::toGuest(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?Reservation $reservation = null, string $type = 'guest', ?string $unsubscribeUrl = null): TemplatedMail`; `NotificationTemplateRenderer::dropEmptyLines(string $body, array $placeholders): string`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/GuestMailDesignTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\Block;
use App\Mail\Layout\TextBlocks;
use App\Mail\TemplatedMail;
use App\Models\Reservation;
use App\Services\NotificationTemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_online_booking_mail_has_headline_details_and_button(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['guest_address' => 'du', 'email_verification_required' => false]);
        $this->clearTenantContext();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(), 'time' => '19:00', 'party_size' => 2,
            'name' => 'Frau Kessler', 'email' => 'gast@example.test', 'phone' => '+49 451 123456', 'privacy_accepted' => '1',
        ])->assertRedirect();

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            $html = $mail->render();

            return in_array($mail->frame?->type, ['reservation_confirmed', 'reservation_requested'], true)
                && str_contains($html, '</h1>')
                && str_contains($html, 'class="panel"')
                && str_contains($html, 'class="btn"')
                && ! str_contains($mail->mailBody, '{');
        });
    }

    public function test_lines_with_empty_placeholder_disappear(): void
    {
        $body = "Hallo {guest_name},\n\nJetzt bezahlen: {payment_link}\nReservierungsnummer: {reservation_code}\n\nTisch: {table_name}\n\n{custom_message}\n\nBis bald!";

        $this->assertSame(
            "Hallo {guest_name},\n\nReservierungsnummer: {reservation_code}\n\n\n{custom_message}\n\nBis bald!",
            NotificationTemplateRenderer::dropEmptyLines($body, ['guest_name' => 'Ana', 'payment_link' => '', 'reservation_code' => 'R-1', 'table_name' => '', 'custom_message' => '']),
        );
    }

    public function test_every_default_template_parses_and_never_repeats_its_headline(): void
    {
        foreach (['du', 'Sie'] as $address) {
            foreach (NotificationTemplateRenderer::defaults($address) as $key => $template) {
                $headline = (string) __("mails.headlines.{$key}.{$address}", [], 'de');
                $this->assertNotSame("mails.headlines.{$key}.{$address}", $headline, "Überschrift fehlt: {$key}");
                $this->assertStringNotContainsStringIgnoringCase($headline, $template['body'], "{$key} ({$address}) wiederholt die Überschrift");

                $body = strtr($template['body'], [
                    '{cancel_link}' => 'https://swayy.test/m', '{payment_link}' => 'https://swayy.test/p',
                    '{feedback_link}' => 'https://swayy.test/f', '{waitlist_link}' => 'https://swayy.test/w',
                ]);
                $blocks = TextBlocks::fromConfig()->parse($body);
                $types = array_map(fn (Block $b) => $b->type, $blocks);
                if (str_contains($template['body'], '_link}')) {
                    $this->assertContains(Block::BUTTON, $types, "{$key} ({$address}) ohne Knopf");
                }
                if (in_array($key, ['reservation_confirmed', 'reservation_requested', 'reservation_reminder', 'payment_pending'], true)) {
                    $this->assertContains(Block::DETAILS, $types, "{$key} ({$address}) ohne Tabelle");
                }
            }
        }
    }

    public function test_sie_defaults_never_say_du_and_du_defaults_never_say_sie(): void
    {
        foreach (NotificationTemplateRenderer::defaults('Sie') as $key => $template) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dir|dein\w*)\b/i', $template['body'], $key);
        }
        foreach (NotificationTemplateRenderer::defaults('du') as $key => $template) {
            $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihnen|Ihr\w*)\b/', $template['body'], $key);
        }
    }
}
```

Hinweis zum ersten Test: Heißt die Einstellung für die E-Mail-Bestätigung in `location_settings` anders als `email_verification_required`, den Namen aus `tests/Feature/EmailConfirmationRequiredTest.php` übernehmen, damit die Buchung ohne Bestätigungslink sofort eine Vorlagen-Mail auslöst.

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=GuestMailDesignTest`
Expected: FAIL (`dropEmptyLines` fehlt, Rahmen fehlt, Überschrift steht im Standardtext)

- [ ] **Step 3: `toGuest` mit Mailart**

In `app/Services/GuestMailSender.php` `use App\Mail\Layout\MailFrameFactory;` ergänzen und `toGuest` ersetzen:

```php
    /**
     * Mail an einen Gast: Name des Betriebs, Antworten an den Betrieb, Rahmen
     * im Auftritt des Betriebs. $type wählt Überschrift und Grund (lang/de/mails.php).
     */
    public function toGuest(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?Reservation $reservation = null, string $type = 'guest', ?string $unsubscribeUrl = null): TemplatedMail
    {
        return new TemplatedMail(
            $subject,
            $body,
            $this->fromName($tenant, $location),
            $this->relayReplyTo($tenant, $reservation) ?? $this->replyTo($tenant, $location),
            app(MailFrameFactory::class)->forGuest($type, $tenant, $location, [], $unsubscribeUrl),
        );
    }
```

- [ ] **Step 4: Renderer – leere Zeilen und neue Standardtexte**

In `NotificationTemplateRenderer::render()` die Zeile `$body = $template['body'];` ersetzen durch:

```php
        $body = self::dropEmptyLines($template['body'], $placeholders);
```

Neue Methode in derselben Klasse (nach `render()`):

```php
    /**
     * Zeilen „Bezeichnung: {platzhalter}“, deren Platzhalter leer bleibt,
     * entfallen ganz – etwa „Jetzt bezahlen: {payment_link}“ ohne offene
     * Zahlung. Gilt auch für Vorlagen, die ein Betrieb selbst geschrieben hat.
     *
     * @param  array<string, mixed>  $placeholders
     */
    public static function dropEmptyLines(string $body, array $placeholders): string
    {
        $lines = preg_split('/\R/u', $body) ?: [];

        $kept = array_filter($lines, static function (string $line) use ($placeholders): bool {
            if (preg_match('/^\s*[^{}:\n]+:\s*\{(\w+)\}\s*$/u', $line, $m) !== 1) {
                return true;
            }

            return trim((string) ($placeholders[$m[1]] ?? '')) !== '';
        });

        return implode("\n", $kept);
    }
```

In `defaults()` beide Arrays (du und Sie) durch diese Texte ersetzen (Betreffzeilen bleiben, wie sie sind):

```php
        if ($address === 'du') {
            return [
                'reservation_confirmed' => [
                    'subject' => 'Reservierungsbestätigung – {location_name} am {reservation_date}',
                    'body' => "Hallo {guest_name},\n\nwir haben deinen Tisch reserviert und freuen uns auf deinen Besuch.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
                ],
                'reservation_requested' => [
                    'subject' => 'Deine Anfrage ist angekommen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nschön, dass du zu uns möchtest. Wir schauen uns jede Anfrage persönlich an und schreiben dir, sobald alles feststeht. Mit unserer Zusage ist dein Platz fest für dich reserviert.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nAnfrage ansehen oder zurückziehen: {cancel_link}\n\nHerzliche Grüße\n{location_name}",
                ],
                'reservation_cancelled' => [
                    'subject' => 'Reservierung storniert – {location_name}',
                    'body' => "Hallo {guest_name},\n\ndiese Reservierung gilt nicht mehr:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nReservierungsnummer: {reservation_code}\n\nWir freuen uns, wenn du ein andermal vorbeikommst.\n{location_name}",
                ],
                'reservation_rejected' => [
                    'subject' => 'Reservierungsanfrage – {location_name}',
                    'body' => "Hallo {guest_name},\n\nfür den {reservation_date} um {reservation_time} Uhr können wir dir leider keinen Tisch zusagen.\n\n{custom_message}\n\nWir freuen uns, wenn du es zu einem anderen Termin versuchst.\n{location_name}",
                ],
                'reservation_reminder' => [
                    'subject' => 'Erinnerung: deine Reservierung morgen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nbald ist es so weit, wir freuen uns auf dich.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
                ],
                'feedback_request' => [
                    'subject' => 'Wie war dein Besuch bei {location_name}?',
                    'body' => "Hallo {guest_name},\n\ndanke für deinen Besuch. Erzähl uns, was dir gefallen hat und was wir besser machen können.\n\nFeedback geben: {feedback_link}\n\n{location_name}",
                ],
                'waitlist_offer' => [
                    'subject' => 'Ein Tisch ist frei geworden – {location_name}',
                    'body' => "Hallo {guest_name},\n\nfür deinen Wartelisteneintrag haben wir jetzt Platz. Bestätige bitte zeitnah, dann halten wir den Tisch für dich.\n\nTisch bestätigen: {waitlist_link}\n\n{location_name}",
                ],
                'payment_pending' => [
                    'subject' => 'Bitte Anzahlung leisten – {location_name} am {reservation_date}',
                    'body' => "Hallo {guest_name},\n\nwir haben deinen Tisch vorgemerkt. Verbindlich wird die Reservierung mit einer Anzahlung von {payment_amount}. Wir verrechnen sie bei deinem Besuch vollständig mit der Rechnung; bei Nichterscheinen behalten wir sie ein.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\nZahlen bis: {payment_deadline} Uhr\n\nJetzt bezahlen: {payment_link}\n\nNach der Frist geben wir den Tisch wieder frei.\n{location_name}",
                ],
                'payment_reminder' => [
                    'subject' => 'Erinnerung: Anzahlung offen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nwir halten deinen Tisch noch bis {payment_deadline} Uhr für dich frei.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nAnzahlung: {payment_amount}\n\nJetzt bezahlen: {payment_link}\n\n{location_name}",
                ],
                'payment_expired' => [
                    'subject' => 'Reservierung verfallen – {location_name}',
                    'body' => "Hallo {guest_name},\n\ndie Anzahlung für den {reservation_date} um {reservation_time} Uhr ist nicht rechtzeitig eingegangen, deshalb haben wir den Tisch wieder freigegeben.\n\nBuch gern jederzeit neu, wir freuen uns auf dich.\n{location_name}",
                ],
            ];
        }

        return [
            'reservation_confirmed' => [
                'subject' => 'Reservierungsbestätigung – {location_name} am {reservation_date}',
                'body' => "Hallo {guest_name},\n\nwir haben Ihren Tisch reserviert und freuen uns auf Ihren Besuch.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
            ],
            'reservation_requested' => [
                'subject' => 'Ihre Anfrage ist angekommen – {location_name}',
                'body' => "Hallo {guest_name},\n\nschön, dass Sie zu uns möchten. Wir schauen uns jede Anfrage persönlich an und schreiben Ihnen, sobald alles feststeht. Mit unserer Zusage ist Ihr Platz fest für Sie reserviert.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nAnfrage ansehen oder zurückziehen: {cancel_link}\n\nHerzliche Grüße\n{location_name}",
            ],
            'reservation_cancelled' => [
                'subject' => 'Reservierung storniert – {location_name}',
                'body' => "Hallo {guest_name},\n\ndiese Reservierung gilt nicht mehr:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nReservierungsnummer: {reservation_code}\n\nWir freuen uns, wenn Sie ein andermal vorbeikommen.\n{location_name}",
            ],
            'reservation_rejected' => [
                'subject' => 'Reservierungsanfrage – {location_name}',
                'body' => "Hallo {guest_name},\n\nfür den {reservation_date} um {reservation_time} Uhr können wir Ihnen leider keinen Tisch zusagen.\n\n{custom_message}\n\nWir freuen uns, wenn Sie es zu einem anderen Termin versuchen.\n{location_name}",
            ],
            'reservation_reminder' => [
                'subject' => 'Erinnerung: Ihre Reservierung morgen – {location_name}',
                'body' => "Hallo {guest_name},\n\nbald ist es so weit, wir freuen uns auf Sie.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
            ],
            'feedback_request' => [
                'subject' => 'Wie war Ihr Besuch bei {location_name}?',
                'body' => "Hallo {guest_name},\n\nvielen Dank für Ihren Besuch. Erzählen Sie uns, was Ihnen gefallen hat und was wir besser machen können.\n\nFeedback geben: {feedback_link}\n\n{location_name}",
            ],
            'waitlist_offer' => [
                'subject' => 'Ein Tisch ist frei geworden – {location_name}',
                'body' => "Hallo {guest_name},\n\nfür Ihren Wartelisteneintrag haben wir jetzt Platz. Bitte bestätigen Sie zeitnah, dann halten wir den Tisch für Sie.\n\nTisch bestätigen: {waitlist_link}\n\n{location_name}",
            ],
            'payment_pending' => [
                'subject' => 'Bitte Anzahlung leisten – {location_name} am {reservation_date}',
                'body' => "Hallo {guest_name},\n\nwir haben Ihren Tisch vorgemerkt. Verbindlich wird die Reservierung mit einer Anzahlung von {payment_amount}. Wir verrechnen sie bei Ihrem Besuch vollständig mit der Rechnung; bei Nichterscheinen behalten wir sie ein.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\nZahlen bis: {payment_deadline} Uhr\n\nJetzt bezahlen: {payment_link}\n\nNach der Frist geben wir den Tisch wieder frei.\n{location_name}",
            ],
            'payment_reminder' => [
                'subject' => 'Erinnerung: Anzahlung offen – {location_name}',
                'body' => "Hallo {guest_name},\n\nwir halten Ihren Tisch noch bis {payment_deadline} Uhr für Sie frei.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nAnzahlung: {payment_amount}\n\nJetzt bezahlen: {payment_link}\n\n{location_name}",
            ],
            'payment_expired' => [
                'subject' => 'Reservierung verfallen – {location_name}',
                'body' => "Hallo {guest_name},\n\ndie Anzahlung für den {reservation_date} um {reservation_time} Uhr ist nicht rechtzeitig eingegangen, deshalb haben wir den Tisch wieder freigegeben.\n\nBuchen Sie gern jederzeit neu, wir freuen uns auf Sie.\n{location_name}",
            ],
        ];
```

Hinweis: Der Test `test_sie_defaults_never_say_du…` prüft „Ihr…“ nur großgeschrieben; die Sie-Texte enthalten kein kleines „ihr“. Taucht `{custom_message}` in der Abgelehnt-Mail leer auf, normalisiert der Rahmen die doppelte Leerzeile.

- [ ] **Step 5: Versandstellen**

`ReservationLifecycleService::sendGuestMail()` – den Aufruf ergänzen:

```php
        Mail::to($reservation->guest_email_snapshot)->queue(
            $this->mailSender->toGuest($rendered['subject'], $rendered['body'], $tenant, $location, $reservation, $templateKey)
        );
```

Tischänderung in derselben Klasse (der `Mail::to(...)->queue($this->mailSender->toGuest(` Aufruf nach `$vars = [...]`) – die beiden Text-Ternäre und die Argumentliste ersetzen:

```php
            Mail::to($reservation->guest_email_snapshot)->queue($this->mailSender->toGuest(
                $du
                    ? __('Tischänderung zu deiner Reservierung :code – :loc', $vars)
                    : __('Tischänderung zu Ihrer Reservierung :code – :loc', $vars),
                $du
                    ? __("Hallo :name,\n\nwir haben dir einen anderen Tisch zugewiesen, damit alles gut passt. Sonst bleibt deine Reservierung, wie sie ist.\n\nDatum: :date\nUhrzeit: :time Uhr\nTisch: :tables\nReservierungsnummer: :code\n\nBei Fragen melde dich gern.\n:loc", $vars)
                    : __("Hallo :name,\n\nwir haben Ihnen einen anderen Tisch zugewiesen, damit alles gut passt. Sonst bleibt Ihre Reservierung, wie sie ist.\n\nDatum: :date\nUhrzeit: :time Uhr\nTisch: :tables\nReservierungsnummer: :code\n\nBei Fragen melden Sie sich gern.\n:loc", $vars),
                $tenant,
                $location,
                $reservation,
                'reservation_table_changed',
            ));
```

`WaitlistService` – im `toGuest(`-Aufruf die beiden Body-Texte ersetzen und die Mailart ergänzen:

```php
            Mail::to($entry->guest_email)->queue(app(GuestMailSender::class)->toGuest(
                __('Ein Tisch ist frei geworden – :location', ['location' => $location->name]),
                $du
                    ? __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden. Bestätige bitte innerhalb von :minutes Minuten, dann halten wir ihn für dich.\n\nTisch bestätigen: :link\n\n:location", $vars)
                    : __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden. Bitte bestätigen Sie innerhalb von :minutes Minuten, dann halten wir ihn für Sie.\n\nTisch bestätigen: :link\n\n:location", $vars),
                $location->tenant()->withoutGlobalScopes()->first(),
                $location,
                type: 'waitlist_offer',
            )->afterCommit());
```

`EventBookingService::sendConfirmationMail()` – alles ab `$paymentBlock = '';` bis zum Ende des `$body = …`-Ausdrucks ersetzen durch:

```php
        $details = [
            'Datum: '.$startLocal->format('d.m.Y'),
            'Uhrzeit: '.$startLocal->format('H:i').' Uhr',
            'Tickets: '.$booking->ticket_count,
            'Buchungsnummer: '.$booking->code,
        ];
        if ($booking->amount_minor) {
            $details[] = 'Betrag: '.number_format($booking->amount_minor / 100, 2, ',', '.').' '.$event->currency;
        }

        $parts = [
            'Hallo '.$booking->guest_name.',',
            $du
                ? 'für „'.$event->title.'“ ist alles gebucht. Wir freuen uns auf dich.'
                : 'für „'.$event->title.'“ ist alles gebucht. Wir freuen uns auf Sie.',
            implode("\n", $details),
        ];
        if ($booking->amount_minor) {
            $parts[] = 'Jetzt bezahlen: '.route('pay.event', ['code' => $booking->code, 'token' => $booking->manage_token]);
            $parts[] = $du
                ? 'Die Vorauszahlung verrechnen wir bei deinem Besuch vollständig mit der Rechnung. Bei Nichterscheinen behalten wir sie ein.'
                : 'Die Vorauszahlung verrechnen wir bei Ihrem Besuch vollständig mit der Rechnung. Bei Nichterscheinen behalten wir sie ein.';
        }
        $parts[] = 'Buchung ansehen oder stornieren: '.$manageLink;
        $parts[] = (string) ($location?->name ?? '');

        $body = implode("\n\n", array_filter($parts, static fn (string $part) => $part !== ''));
```

und im folgenden `toGuest(`-Aufruf nach `$location,` ergänzen: `type: 'event_booking_confirmed',`. Die nicht mehr benutzte Variable `$vars` dort entfernen.

- [ ] **Step 6: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='GuestMailDesignTest|Waitlist|EventBooking|Reservation'`
Expected: PASS. Bricht ein bestehender Test an einem alten Wortlaut („Stornieren:“, „Bitte bestätige innerhalb“, „Jetzt online bezahlen“), dort die neue Formulierung aus diesem Schritt eintragen; die geprüfte Sache (Link, Betrag, Code) bleibt dieselbe.

- [ ] **Step 7: Commit**

```bash
git add app/Services tests/Feature/GuestMailDesignTest.php tests/Feature
git commit -m "feat: Gast-Mails im neuen Rahmen; Standardtexte auf Überschriften abgestimmt, leere Angaben entfallen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Mails an den Betrieb (Swayy-Auftritt)

**Files:**
- Modify: `app/Services/GuestMailSender.php` (`toOperator`)
- Modify: `app/Services/ReservationLifecycleService.php` (`sendOwnerNotification`, Block `$lines = [` bis Ende des `Mail::to($to)->queue(...)`-Aufrufs)
- Modify: `app/Services/RefundService.php` (`notifyOperator`)
- Modify: `app/Mail/ForwardedGuestReplyMail.php`, `app/Services/Mail/InboundGuestReplyRouter.php` (`forward`)
- Delete: `resources/views/mail/templated-text.blade.php`
- Test: `tests/Feature/OperatorMailDesignTest.php` (neu)

**Interfaces:**
- Consumes: `MailFrameFactory::forSwayy`, `MailFrameFactory::ownerDu` (Task 4), `RendersInFrame` (Task 5)
- Produces (Hinweis: Knöpfe im Swayy-Auftritt nur für `MailFrameFactory::buttonHosts()`, also `app.url`, den aktuellen Host und das Kundenpanel): `GuestMailSender::toOperator(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?string $guestEmail = null, string $type = 'operator_new_reservation', array $vars = []): TemplatedMail`; `ForwardedGuestReplyMail::__construct(..., array $forwardedAttachments = [], ?MailFrame $frame = null)`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/OperatorMailDesignTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use App\Mail\Layout\MailFrame;
use App\Mail\TemplatedMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OperatorMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function bookWithNote(string $note): array
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->update(['name' => 'Trattoria Luna']);
        $setup['location']->settings()->update(['owner_notification_enabled' => true, 'owner_notification_email' => 'kueche@luna.example']);
        $this->clearTenantContext();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(), 'time' => '19:00', 'party_size' => 4,
            'name' => 'Erika Muster', 'email' => 'erika@example.test', 'phone' => '+49 170 111', 'privacy_accepted' => '1',
            'note' => $note,
        ])->assertRedirect();

        return $setup;
    }

    public function test_new_reservation_mail_comes_from_swayy_with_tables_and_admin_button(): void
    {
        config(['swayy.mail.owner_address' => 'du']);
        $this->bookWithNote('Fensterplatz, wenn möglich');

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            if ($mail->frame?->type !== 'operator_new_reservation') {
                return false;
            }
            $html = $mail->render();

            return $mail->frame->look === MailFrame::LOOK_SWAYY
                && str_contains($html, 'Neue Reservierung für Trattoria Luna</h1>')
                && substr_count($html, 'class="panel"') === 2
                && str_contains($html, '>Reservierung öffnen</a>')
                && str_contains($html, 'href="'.route('admin.reservations.index').'/')
                && str_contains($html, 'Fensterplatz, wenn möglich')
                && ! str_contains($html, 'Reserviert mit');
        });
    }

    public function test_url_in_guest_note_never_becomes_a_button(): void
    {
        $this->bookWithNote('Jetzt bezahlen: https://fremde-seite.example/pay');

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            if ($mail->frame?->type !== 'operator_new_reservation') {
                return false;
            }
            $html = $mail->render();

            return ! preg_match('/<a href="https:\/\/fremde-seite\.example\/pay" style="display:inline-block/', $html)
                && str_contains($html, 'fremde-seite.example/pay');
        });
    }

    public function test_forwarded_guest_reply_is_framed_but_never_structured(): void
    {
        $mail = new ForwardedGuestReplyMail(
            forwardSubject: 'Antwort von Erika: Tisch',
            forwardBody: "Antwort zur Buchung R-1:\n\nDatum: morgen\nUhrzeit: später\nZahlung: https://fremde-seite.example/x",
            fromName: 'Trattoria Luna',
            guestReplyTo: 'erika@example.test',
        );
        $html = $mail->render();

        $this->assertStringContainsString('Antwort von erika@example.test</h1>', $html);
        $this->assertStringNotContainsString('class="panel"', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $this->assertStringContainsString('fremde-seite.example/x', $html);
        $mail->assertSeeInText('Zahlung: https://fremde-seite.example/x');
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=OperatorMailDesignTest`
Expected: FAIL

- [ ] **Step 3: `toOperator` umstellen**

In `app/Services/GuestMailSender.php`:

```php
    /**
     * Mail an den Betrieb im Swayy-Auftritt. Mit $guestEmail gehen Antworten
     * direkt an den Gast, sofern owner_reply_to_guest das erlaubt. Anzeigename
     * ist MAIL_FROM_NAME.
     *
     * @param  array<string, string>  $vars
     */
    public function toOperator(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?string $guestEmail = null, string $type = 'operator_new_reservation', array $vars = []): TemplatedMail
    {
        $antwort = $guestEmail !== null
            && config('swayy.guest_mail.owner_reply_to_guest', true)
            && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)
                ? $guestEmail
                : null;

        return new TemplatedMail($subject, $body, null, $antwort, app(MailFrameFactory::class)->forSwayy($type, [
            'location' => (string) ($location?->name ?? ''),
            'tenant' => (string) ($tenant?->name ?? ''),
            ...$vars,
        ]));
    }
```

- [ ] **Step 4: Neue-Reservierung-Mail**

In `ReservationLifecycleService::sendOwnerNotification()` `use App\Mail\Layout\MailFrameFactory;` oben ergänzen und den Block ab `$lines = [` bis einschließlich `));` des `Mail::to($to)->queue(`-Aufrufs ersetzen:

```php
        $du = MailFrameFactory::ownerDu();
        $requested = $reservation->status->value === 'requested';

        $intro = 'Eine neue Reservierung ist eingegangen. '.match (true) {
            $requested && $du => 'Sie wartet auf deine Bestätigung.',
            $requested => 'Sie wartet auf Ihre Bestätigung.',
            $du => 'Sie steht schon in deinem Reservierungsbuch.',
            default => 'Sie steht schon in Ihrem Reservierungsbuch.',
        };

        $reservierung = [
            'Datum: '.$localStart->format('d.m.Y'),
            'Uhrzeit: '.$localStart->format('H:i').' Uhr',
            'Personen: '.$reservation->party_size,
            'Tisch: '.($tables !== '' ? $tables : 'automatisch/offen'),
            'Status: '.__('reservations.status.'.$reservation->status->value, [], 'de'),
            'Reservierungsnummer: '.$reservation->code,
        ];
        $gast = [
            'Gast: '.$reservation->guest_name_snapshot,
            'E-Mail: '.($reservation->guest_email_snapshot ?: '—'),
            'Telefon: '.($reservation->guest_phone_snapshot ?: '—'),
            'Quelle: '.$sourceLabel,
        ];
        if ($reservation->occasion) {
            $gast[] = 'Anlass: '.$reservation->occasion;
        }
        if ($reservation->allergy_note) {
            $gast[] = 'Allergien: '.$reservation->allergy_note;
        }
        if ($reservation->guest_note) {
            $gast[] = 'Gastnotiz: '.$reservation->guest_note;
        }

        $body = implode("\n\n", [
            $intro,
            implode("\n", $reservierung),
            implode("\n", $gast),
            'Reservierung öffnen: '.route('admin.reservations.show', $reservation),
        ]);

        $tenant = $reservation->tenant()->first();
        $subject = 'Neue Reservierung – '.$localStart->format('d.m.').' '.$localStart->format('H:i').' · '
            .$reservation->guest_name_snapshot.' ('.$reservation->party_size.' P.)';

        Mail::to($to)->queue($this->mailSender->toOperator(
            $subject,
            $body,
            $tenant,
            $location,
            $reservation->guest_email_snapshot,
            'operator_new_reservation',
        ));
```

Die Zeilen danach (`NotificationLog::…`) bleiben.

- [ ] **Step 5: Erstattung und weitergeleitete Antwort**

`RefundService::notifyOperator()` – im `toOperator(`-Aufruf nach `$location,` ergänzen: `type: 'operator_refund',`.

`app/Mail/ForwardedGuestReplyMail.php` – `use App\Mail\Concerns\RendersInFrame; use App\Mail\Layout\MailFrame; use App\Mail\Layout\MailFrameFactory;` ergänzen, `use Queueable, RendersInFrame, SerializesModels;`, Konstruktor um `public readonly ?MailFrame $frame = null,` als letzten Parameter erweitern und `content()` ersetzen:

```php
    public function content(): Content
    {
        // Text des Gastes: gerahmt, aber ohne Tabelle und Knöpfe (Spec, Review Focus 1).
        return $this->framedContent(
            $this->forwardBody,
            $this->frame ?? app(MailFrameFactory::class)->forSwayy('operator_guest_reply', ['guest' => $this->guestReplyTo], structured: false),
        );
    }
```

`InboundGuestReplyRouter::forward()` – im `new ForwardedGuestReplyMail(`-Aufruf nach `forwardedAttachments: …,` ergänzen:

```php
            frame: app(MailFrameFactory::class)->forSwayy('operator_guest_reply', [
                'guest' => $gastName,
                'location' => (string) ($location?->name ?? $tenant->name),
            ], structured: false),
```

(`use App\Mail\Layout\MailFrameFactory;` oben ergänzen.)

`git rm resources/views/mail/templated-text.blade.php` (keine Nutzer mehr; prüfen mit `grep -rn "templated-text" app resources` → leer).

- [ ] **Step 6: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='OperatorMailDesignTest|OwnerNotificationTest|ForwardedGuestReply|Refund|InboundGuestReply'`
Expected: PASS (`OwnerNotificationTest` prüft Personenzahl, Kontakt und Allergien im `mailBody`; alle bleiben enthalten).

- [ ] **Step 7: Commit**

```bash
git add app/Services app/Mail/ForwardedGuestReplyMail.php tests/Feature/OperatorMailDesignTest.php
git commit -m "feat: Mails an den Betrieb im Swayy-Auftritt mit Knopf in die Verwaltung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Link-Mails für Gäste (Bestätigung, Anmeldung)

**Files:**
- Create: `app/Support/Duration.php`
- Modify: `app/Mail/GuestLinkMail.php`, `app/Services/GuestAuthService.php` (`sendMagicLink`, `sendVerification`)
- Delete: `resources/views/mail/guest-link.blade.php`
- Test: `tests/Unit/DurationTest.php`, `tests/Feature/GuestLinkMailDesignTest.php`

**Interfaces:**
- Consumes: `RendersInFrame` (Task 5), `MailFrameFactory::forGuest` (Task 4), `swayy.guest_auth.*` (Task 1)
- Produces: `Duration::minutes(int $minutes): string` („60 Minuten“, „1 Stunde“, „24 Stunden“, „2 Tage“); `GuestLinkMail::__construct(string $subjectLine, string $intro, string $url, string $buttonLabel, ?string $fromName = null, ?string $replyToAddress = null, ?MailFrame $frame = null, string $note = '')`.

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/DurationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_minutes_read_naturally(): void
    {
        $this->assertSame('45 Minuten', Duration::minutes(45));
        $this->assertSame('1 Minute', Duration::minutes(1));
        $this->assertSame('1 Stunde', Duration::minutes(60));
        $this->assertSame('90 Minuten', Duration::minutes(90));
        $this->assertSame('24 Stunden', Duration::minutes(1440));
        $this->assertSame('2 Tage', Duration::minutes(2880));
        $this->assertSame('36 Stunden', Duration::minutes(2160));
    }
}
```

(Unter 48 Stunden zählen volle Stunden, ab 48 Stunden ganze Tage; 1440 Minuten heißen also „24 Stunden“.)

`tests/Feature/GuestLinkMailDesignTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\GuestLinkMail;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestLinkMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array{0: Guest, 1: Reservation, 2: array<string, mixed>} */
    private function guestWithReservation(string $address): array
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['name' => 'Trattoria Luna']);
        $setup['location']->settings()->update(['guest_address' => $address]);
        $this->clearTenantContext();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'first_name' => 'Erika', 'email' => 'erika@example.test']);
        $reservation = Reservation::factory()->create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'guest_id' => $guest->id]);

        return [$guest, $reservation, $setup];
    }

    public function test_verification_mail_is_framed_in_the_location_tone(): void
    {
        Mail::fake();
        [$guest, $reservation] = $this->guestWithReservation('du');

        app(GuestAuthService::class)->sendVerification($guest, $reservation);

        Mail::assertSent(GuestLinkMail::class, function (GuestLinkMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Bitte bestätige deine E-Mail-Adresse</h1>')
                && str_contains($html, '>E-Mail-Adresse bestätigen</a>')
                && preg_match('/font-size:13px[^>]*>Der Link gilt 24 Stunden\./', $html) === 1
                && str_contains($html, 'weil mit dieser Adresse bei Trattoria Luna reserviert wurde')
                && ! str_contains($html, 'Sie ');
        });
    }

    public function test_link_validity_comes_from_the_settings(): void
    {
        Mail::fake();
        config(['swayy.guest_auth.verify_minutes' => 120]);
        [$guest, $reservation] = $this->guestWithReservation('Sie');

        app(GuestAuthService::class)->sendVerification($guest, $reservation);

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $mail) => str_contains($mail->render(), 'Der Link gilt 2 Stunden.')
            && str_contains($mail->render(), 'Bitte bestätigen Sie Ihre E-Mail-Adresse</h1>'));
    }

    public function test_login_link_mail_has_its_headline_and_validity(): void
    {
        Mail::fake();
        [$guest, , $setup] = $this->guestWithReservation('Sie');

        app(GuestAuthService::class)->sendMagicLink($setup['tenant'], 'erika@example.test');

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $mail) => str_contains($mail->render(), 'Ihr Anmeldelink</h1>')
            && str_contains($mail->render(), '>Jetzt anmelden</a>')
            && str_contains($mail->render(), 'Der Link gilt 1 Stunde.'));
    }
}
```

Hinweis: Falls `Reservation::factory()` weitere Pflichtfelder verlangt, `tests/Feature/EmailConfirmationRequiredTest.php` als Vorlage für das Anlegen nehmen.

- [ ] **Step 2: Tests laufen lassen, sie schlagen fehl**

Run: `PAO_DISABLE=1 php artisan test --filter='DurationTest|GuestLinkMailDesignTest'`
Expected: FAIL

- [ ] **Step 3: Umsetzen**

`app/Support/Duration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gültigkeitsdauer in Worten für Mails und Hinweise.
 */
final class Duration
{
    public static function minutes(int $minutes): string
    {
        if ($minutes >= 2880 && $minutes % 1440 === 0) {
            return ($minutes / 1440).' Tage';
        }
        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 Stunde' : $hours.' Stunden';
        }

        return $minutes === 1 ? '1 Minute' : $minutes.' Minuten';
    }
}
```

`app/Mail/GuestLinkMail.php` vollständig ersetzen:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestLinkMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $intro,
        public string $url,
        public string $buttonLabel,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public ?MailFrame $frame = null,
        public string $note = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Absenderadresse bleibt MAIL_FROM_ADDRESS (SPF/DKIM), nur der
            // Anzeigename ist der des Betriebs.
            from: new Address(
                config('mail.from.address'),
                $this->fromName ?: config('mail.from.name'),
            ),
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        $text = $this->intro."\n\n".$this->buttonLabel.': '.$this->url
            .($this->note !== '' ? "\n\n---\n\n".$this->note : '');

        return $this->framedContent($text, $this->frame ?? app(MailFrameFactory::class)->forGuest('guest_login', null, null));
    }
}
```

`app/Services/GuestAuthService.php` – `use App\Mail\Layout\MailFrameFactory; use App\Support\Duration;` ergänzen.

In `sendMagicLink()` `$token = $this->issue($guest, 'login', null, 60);` ersetzen durch

```php
        $minutes = (int) config('swayy.guest_auth.login_minutes', 60);
        $token = $this->issue($guest, 'login', null, $minutes);
```

und den `Mail::to(...)->send(new GuestLinkMail(...))`-Aufruf ersetzen durch:

```php
        $name = $guest->first_name ?: $guest->last_name;
        Mail::to($guest->email)->send(new GuestLinkMail(
            $du ? __('Dein Anmeldelink für :name', ['name' => $tenant->name])
                : __('Ihr Anmeldelink für :name', ['name' => $tenant->name]),
            $du ? __("Hallo :guest,\n\nhier ist dein Anmeldelink für dein Kundenkonto bei :name.", ['guest' => $name, 'name' => $tenant->name])
                : __("Hallo :guest,\n\nhier ist Ihr Anmeldelink für Ihr Kundenkonto bei :name.", ['guest' => $name, 'name' => $tenant->name]),
            $url,
            __('Jetzt anmelden'),
            $sender->fromName($tenant, $location),
            $sender->replyTo($tenant, $location),
            app(MailFrameFactory::class)->forGuest('guest_login', $tenant, $location),
            $du ? __('Der Link gilt :dauer. Hast du ihn nicht angefordert, kannst du diese Mail löschen.', ['dauer' => Duration::minutes($minutes)])
                : __('Der Link gilt :dauer. Haben Sie ihn nicht angefordert, können Sie diese Mail löschen.', ['dauer' => Duration::minutes($minutes)]),
        ));
```

In `sendVerification()` `$token = $this->issue($guest, 'verify', $reservation->id, 1440);` ersetzen durch

```php
        $minutes = (int) config('swayy.guest_auth.verify_minutes', 1440);
        $token = $this->issue($guest, 'verify', $reservation->id, $minutes);
```

und den Mail-Aufruf ersetzen durch:

```php
        $name = $guest->first_name ?: $guest->last_name;
        Mail::to($guest->email)->send(new GuestLinkMail(
            $du ? __('Bitte bestätige deine E-Mail-Adresse')
                : __('Bitte bestätigen Sie Ihre E-Mail-Adresse'),
            $du ? __("Hallo :guest,\n\nein Klick noch, dann ist deine Buchung :code bei uns.", ['guest' => $name, 'code' => $reservation->code])
                : __("Hallo :guest,\n\nein Klick noch, dann ist Ihre Buchung :code bei uns.", ['guest' => $name, 'code' => $reservation->code]),
            $url,
            __('E-Mail-Adresse bestätigen'),
            $sender->fromName($tenant, $location),
            $sender->replyTo($tenant, $location),
            app(MailFrameFactory::class)->forGuest('guest_verify', $tenant, $location),
            $du ? __('Der Link gilt :dauer. Hast du nicht bei uns reserviert, kannst du diese Mail löschen.', ['dauer' => Duration::minutes($minutes)])
                : __('Der Link gilt :dauer. Haben Sie nicht bei uns reserviert, können Sie diese Mail löschen.', ['dauer' => Duration::minutes($minutes)]),
        ));
```

`git rm resources/views/mail/guest-link.blade.php` (prüfen: `grep -rn "mail.guest-link" app resources` → leer).

- [ ] **Step 4: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='DurationTest|GuestLinkMailDesignTest|CriticalMailsSyncTest|EmailConfirmationRequiredTest|GuestMailReplyToTest|GuestPortal'`
Expected: PASS (die Link-Mails gehen weiter sofort raus).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Duration.php app/Mail/GuestLinkMail.php app/Services/GuestAuthService.php tests/Unit/DurationTest.php tests/Feature/GuestLinkMailDesignTest.php
git commit -m "feat: Bestätigungs- und Anmeldelink als gestaltete Mail; Gültigkeit aus den Einstellungen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Marketing-Mails mit Abmelde-Kopf und Abmeldung per Klick

**Files:**
- Modify: `app/Services/MarketingCampaignService.php` (`deliver`), `app/Http/Controllers/Admin/MarketingCampaignController.php` (`test`)
- Modify: `app/Http/Controllers/Public/MarketingUnsubscribeController.php`, `routes/web.php` (bei `marketing.unsubscribe`), `bootstrap/app.php` (`validateCsrfTokens`)
- Test: `tests/Feature/MarketingMailHeadersTest.php`

**Interfaces:**
- Consumes: `toGuest(..., type: 'marketing', unsubscribeUrl: …)` (Task 6), `framedHeaders` (Task 5)
- Produces: Route `POST /marketing/abmelden/{guest}` (`marketing.unsubscribe.oneclick`); `MarketingUnsubscribeController::oneClick(Request $request, int $guest)`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/MarketingMailHeadersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Services\GuestMailSender;
use App\Services\MarketingCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MarketingMailHeadersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function guest(): Guest
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        return Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'email' => 'gast@example.test', 'marketing_consent' => true]);
    }

    public function test_campaign_mail_carries_one_click_unsubscribe_headers_and_footer_link(): void
    {
        $guest = $this->guest();
        $url = app(MarketingCampaignService::class)->unsubscribeUrl($guest);
        $location = $guest->tenant->locations()->withoutGlobalScope('tenant')->first();

        $mail = app(GuestMailSender::class)->toGuest('Neue Karte', "Hallo,\n\nab Freitag gibt es Trüffel.\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$url}", $guest->tenant, $location, type: 'marketing', unsubscribeUrl: $url);

        $headers = $mail->headers()->text;
        $this->assertSame('<'.$url.'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        $html = $mail->render();
        $this->assertStringContainsString('>Abmelden</a>', $html);
        $this->assertStringNotContainsString('Hier abmelden', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $mail->assertSeeInText('Hier abmelden: '.$url);
    }

    public function test_one_click_post_unsubscribes_with_a_valid_signature(): void
    {
        $guest = $this->guest();
        $url = app(MarketingCampaignService::class)->unsubscribeUrl($guest);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertFalse($guest->fresh()->marketing_consent);
        $this->assertDatabaseHas('guest_consents', ['guest_id' => $guest->id, 'type' => 'marketing', 'granted' => false]);
    }

    public function test_one_click_post_without_signature_changes_nothing(): void
    {
        $guest = $this->guest();

        $this->post('/marketing/abmelden/'.$guest->id, ['List-Unsubscribe' => 'One-Click'])->assertForbidden();

        $this->assertTrue($guest->fresh()->marketing_consent);
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=MarketingMailHeadersTest`
Expected: FAIL (POST-Route fehlt → 405/419)

- [ ] **Step 3: Umsetzen**

`MarketingCampaignService::deliver()` – im `toGuest(`-Aufruf nach `$location,` ergänzen:

```php
            type: 'marketing',
            unsubscribeUrl: $this->unsubscribeUrl($guest),
```

`MarketingCampaignController::test()` – im `toGuest(`-Aufruf nach `$location,` ergänzen:

```php
            type: 'marketing',
            unsubscribeUrl: $this->campaigns->unsubscribeUrl($sample),
```

`MarketingUnsubscribeController` – den Rumpf von `__invoke` in eine private Methode ziehen und `oneClick` ergänzen:

```php
    public function __invoke(Request $request, int $guest)
    {
        abort_unless($request->hasValidSignature(), 403);

        [$name, $alreadyGone] = $this->unsubscribe($guest);

        return view('public.unsubscribed', ['name' => $name, 'alreadyGone' => $alreadyGone]);
    }

    /**
     * Abmeldung per Klick aus dem Mailprogramm (RFC 8058): POST auf denselben
     * signierten Link, ohne Bestätigungsseite.
     */
    public function oneClick(Request $request, int $guest)
    {
        abort_unless($request->hasValidSignature(), 403);

        $this->unsubscribe($guest);

        return response('Abgemeldet.', 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * @return array{0: ?string, 1: bool} Vorname für die Seite, Profil nicht mehr vorhanden
     */
    private function unsubscribe(int $guest): array
    {
        $profile = Guest::withoutGlobalScope('tenant')->find($guest);

        // A profile that is gone (deleted, anonymised) means there is nothing
        // left to unsubscribe – say so calmly instead of showing an error.
        if ($profile === null || $profile->anonymized) {
            return [null, true];
        }

        if ($profile->marketing_consent) {
            $profile->update(['marketing_consent' => false, 'marketing_consent_at' => now()]);

            GuestConsent::withoutGlobalScopes()->create([
                'tenant_id' => $profile->tenant_id,
                'guest_id' => $profile->id,
                'type' => 'marketing',
                'granted' => false,
                'channel' => 'email_link',
                'recorded_at' => now(),
            ]);

            $this->audit->log('guest.marketing_unsubscribed', $profile, null, null, null, null, $profile->tenant_id);
        }

        return [$profile->first_name ?: $profile->last_name, false];
    }
```

`routes/web.php` – direkt nach der GET-Route `marketing.unsubscribe`:

```php
Route::post('/marketing/abmelden/{guest}', [MarketingUnsubscribeController::class, 'oneClick'])
    ->middleware('throttle:booking-slots')->name('marketing.unsubscribe.oneclick');
```

`bootstrap/app.php` – in `validateCsrfTokens(except: [...])` ergänzen:

```php
            // Abmeldung per Klick aus dem Mailprogramm (RFC 8058); geschützt durch die Signatur.
            'marketing/abmelden/*',
```

- [ ] **Step 4: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='MarketingMailHeadersTest|Marketing'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/MarketingCampaignService.php app/Http/Controllers routes/web.php bootstrap/app.php tests/Feature/MarketingMailHeadersTest.php
git commit -m "feat: Kampagnen-Mails mit List-Unsubscribe und Abmeldung per Klick (RFC 8058)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Swayy-Mails (du/Sie aus der Einstellung)

**Files:**
- Modify: `app/Mail/PasswordResetMail.php`, `TrialExpiryWarningMail.php`, `BillingRequestConfirmMail.php`, `BillingRequestOwnerMail.php`, `ContactRequestMail.php`, `ProvisionedWelcomeMail.php`, `ProvisionedApprovedMail.php`
- Modify: `resources/views/mail/password-reset.blade.php`, `trial-expiry-warning.blade.php`, `billing-request-confirm.blade.php`, `billing-request-owner.blade.php`, `contact-request.blade.php`, `provisioning-welcome.blade.php`, `provisioning-approved.blade.php`
- Modify: `app/Http/Controllers/Admin/BillingRequestController.php` (72 Stunden aus der Einstellung), `app/Http/Controllers/Admin/DirectDebitController.php`, `app/Http/Controllers/GoCardlessWebhookController.php` (Texte du/Sie)
- Test: `tests/Feature/SwayyMailDesignTest.php`

**Interfaces:**
- Consumes: `RendersInFrame`, `MailFrameFactory::forSwayy|ownerDu`, `Duration::minutes`, `swayy.billing_request.confirm_hours`
- Produces: jede Swayy-Mail rendert HTML im Swayy-Auftritt; Text-Views bekommen `$du` (bool).

Gemeinsames Muster für jede der sieben Klassen: `use App\Mail\Concerns\RendersInFrame; use App\Mail\Layout\MailFrameFactory;` ergänzen, `use Queueable, RendersInFrame, SerializesModels;`, und `content()` so bauen:

```php
    public function content(): Content
    {
        $du = MailFrameFactory::ownerDu();

        return $this->framedContent(
            view('mail.<view>', [/* Daten der View */ 'du' => $du])->render(),
            app(MailFrameFactory::class)->forSwayy('<mailart>', [/* Platzhalter */]),
        );
    }
```

Text-Views geben reinen Text aus und nutzen deshalb `{!! !!}`; maskiert wird erst im HTML-Rahmen.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/SwayyMailDesignTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BillingRequestConfirmMail;
use App\Mail\BillingRequestOwnerMail;
use App\Mail\ContactRequestMail;
use App\Mail\PasswordResetMail;
use App\Mail\ProvisionedApprovedMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Mail\TrialExpiryWarningMail;
use App\Models\BillingRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SwayyMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function billingRequest(string $notes = ''): BillingRequest
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        return BillingRequest::query()->forceCreate([
            'tenant_id' => $setup['tenant']->id, 'token' => str_repeat('a', 40), 'plan_key' => 'starter',
            'contact_name' => 'Olivia Owner', 'contact_email' => 'olivia@example.test', 'address_line1' => 'Beispielstraße 12',
            'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE', 'notes' => $notes,
        ]);
    }

    /** @return array<string, array{0: Mailable, 1: string, 2: string, 3: string}> Mail, Überschrift du, Überschrift Sie, Knopftext */
    private function mails(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Trattoria Luna', 'trial_ends_at' => now()->addDays(5)]);
        $request = $this->billingRequest();

        return [
            'password' => [new PasswordResetMail('https://swayy.test/passwort/abc'), 'Neues Passwort festlegen', 'Neues Passwort festlegen', 'Neues Passwort festlegen'],
            'trial' => [new TrialExpiryWarningMail($tenant, 'Olivia'), 'Deine Testphase endet bald', 'Ihre Testphase endet bald', 'Tarif wählen'],
            'billing_confirm' => [new BillingRequestConfirmMail($request), 'Bitte bestätige deine Anfrage', 'Bitte bestätigen Sie Ihre Anfrage', 'Anfrage bestätigen'],
            'welcome' => [new ProvisionedWelcomeMail('Olivia', 'Trattoria Luna', false, 'https://swayy.test/passwort', 'https://swayy.test/login', 'https://panel.test/vertraege'), 'Willkommen bei Swayy', 'Willkommen bei Swayy', 'Zum Kundenbereich'],
            'approved' => [new ProvisionedApprovedMail('Olivia', 'Trattoria Luna', 'https://swayy.test/login'), 'Dein Zugang ist freigeschaltet', 'Ihr Zugang ist freigeschaltet', 'Zur Anmeldung'],
        ];
    }

    public function test_swayy_mails_follow_the_owner_address_and_show_their_button(): void
    {
        config(['app.url' => 'https://swayy.test', 'provisioning.panel_base_url' => 'https://panel.test']);

        foreach (['du' => 1, 'Sie' => 2] as $address => $column) {
            config(['swayy.mail.owner_address' => $address]);
            foreach ($this->mails() as $name => $case) {
                $html = $case[0]->render();
                $this->assertStringContainsString($case[$column].'</h1>', $html, "{$name} ({$address})");
                $this->assertStringContainsString('>'.$case[3].'</a>', $html, "{$name} ({$address}) Knopf");
                $this->assertStringContainsString('alt="Swayy"', $html, $name);
                $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', $html, $name);
            }
        }
    }

    public function test_sie_texts_never_use_du(): void
    {
        config(['swayy.mail.owner_address' => 'Sie', 'app.url' => 'https://swayy.test', 'provisioning.panel_base_url' => 'https://panel.test']);

        foreach ($this->mails() as $name => $case) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dir|dein\w*)\b/i', strip_tags($case[0]->render()), $name);
        }
    }

    public function test_contact_message_is_never_structured(): void
    {
        $html = (new ContactRequestMail('Eve', 'eve@example.test', "Jetzt bezahlen: https://fremde-seite.example/pay\nDatum: heute\nUhrzeit: jetzt"))->render();

        $this->assertStringContainsString('Neue Kontaktanfrage</h1>', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $this->assertStringNotContainsString('class="panel"', $html);
    }

    public function test_customer_note_in_billing_owner_mail_never_becomes_a_button(): void
    {
        config(['app.url' => 'https://swayy.test']);
        $html = (new BillingRequestOwnerMail($this->billingRequest('Mehr Infos: https://fremde-seite.example/x')))->render();

        $this->assertStringContainsString('Neue Tarifanfrage von', $html);
        $this->assertDoesNotMatchRegularExpression('/<a href="https:\/\/fremde-seite\.example\/x" style="display:inline-block/', $html);
        $this->assertStringContainsString('>Konto freischalten</a>', $html);
    }

    public function test_billing_link_validity_comes_from_the_settings(): void
    {
        config(['swayy.billing_request.confirm_hours' => 24, 'swayy.mail.owner_address' => 'du']);

        $this->assertStringContainsString('Der Link gilt 24 Stunden.', (new BillingRequestConfirmMail($this->billingRequest()))->render());
    }
}
```

Hinweis: Fehlen beim Anlegen von `BillingRequest` Pflichtspalten, die Felder aus `database/migrations/*billing_requests*` ergänzen; `TrialExpiryWarningMail` braucht `trial_ends_at`.

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=SwayyMailDesignTest`
Expected: FAIL

- [ ] **Step 3: Klassen umstellen**

`PasswordResetMail::content()`:

```php
    public function content(): Content
    {
        $du = MailFrameFactory::ownerDu();
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return $this->framedContent(
            view('mail.password-reset', ['url' => $this->url, 'du' => $du, 'dauer' => Duration::minutes($minutes)])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_password_reset'),
        );
    }
```

(`use App\Support\Duration;` ergänzen.)

`TrialExpiryWarningMail`: Betreff `MailFrameFactory::ownerDu() ? 'Deine Testphase endet bald – Swayy' : 'Ihre Testphase endet bald – Swayy'`; `content()`:

```php
    public function content(): Content
    {
        $du = MailFrameFactory::ownerDu();

        return $this->framedContent(
            view('mail.trial-expiry-warning', [
                'tenant' => $this->tenant, 'recipientName' => $this->recipientName, 'du' => $du,
                'support' => config('services.support_email') ?: config('mail.from.address'),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_trial_expiry', ['tenant' => (string) $this->tenant->name]),
        );
    }
```

`BillingRequestConfirmMail`: Betreff `MailFrameFactory::ownerDu() ? 'Bitte bestätige deine Anfrage – Swayy' : 'Bitte bestätigen Sie Ihre Anfrage – Swayy'`; `content()`:

```php
    public function content(): Content
    {
        return $this->framedContent(
            view('mail.billing-request-confirm', [
                'billingRequest' => $this->billingRequest, 'confirmUrl' => $this->confirmUrl,
                'du' => MailFrameFactory::ownerDu(), 'hours' => (int) config('swayy.billing_request.confirm_hours', 72),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_billing_confirm'),
        );
    }
```

`BillingRequestOwnerMail`: Betreff `'Neue Tarifanfrage: '.$this->billingRequest->tenant->name`; `content()`:

```php
    public function content(): Content
    {
        return $this->framedContent(
            view('mail.billing-request-owner', ['billingRequest' => $this->billingRequest, 'activateUrl' => $this->activateUrl])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_billing_owner', ['tenant' => (string) $this->billingRequest->tenant->name]),
        );
    }
```

`ContactRequestMail::content()`:

```php
    public function content(): Content
    {
        return $this->framedContent(
            view('mail.contact-request', ['senderName' => $this->senderName, 'senderEmail' => $this->senderEmail, 'messageText' => $this->messageText])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_contact', structured: false),
        );
    }
```

`ProvisionedWelcomeMail::content()`:

```php
    public function content(): Content
    {
        return $this->framedContent(
            view('mail.provisioning-welcome', [
                'recipientName' => $this->recipientName, 'tenantName' => $this->tenantName, 'waitsForApproval' => $this->waitsForApproval,
                'passwordUrl' => $this->passwordUrl, 'loginUrl' => $this->loginUrl, 'panelUrl' => $this->panelUrl,
                'du' => MailFrameFactory::ownerDu(),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_provisioned_welcome', ['tenant' => $this->tenantName]),
        );
    }
```

`ProvisionedApprovedMail::content()`:

```php
    public function content(): Content
    {
        return $this->framedContent(
            view('mail.provisioning-approved', [
                'recipientName' => $this->recipientName, 'tenantName' => $this->tenantName, 'loginUrl' => $this->loginUrl,
                'du' => MailFrameFactory::ownerDu(),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_provisioned_approved', ['tenant' => $this->tenantName]),
        );
    }
```

- [ ] **Step 4: Text-Views neu schreiben**

`resources/views/mail/password-reset.blade.php`:

```blade
@if ($du)
Hallo,

für dein Swayy-Konto wurde ein neues Passwort angefordert. Über den Knopf legst du es fest.

Neues Passwort festlegen: {!! $url !!}

---

Der Link gilt {!! $dauer !!}. Hast du kein neues Passwort angefordert, bleibt dein Konto, wie es ist, und du kannst diese Mail löschen.
@else
Guten Tag,

für Ihr Swayy-Konto wurde ein neues Passwort angefordert. Über den Knopf legen Sie es fest.

Neues Passwort festlegen: {!! $url !!}

---

Der Link gilt {!! $dauer !!}. Haben Sie kein neues Passwort angefordert, bleibt Ihr Konto, wie es ist, und Sie können diese Mail löschen.
@endif
```

`resources/views/mail/trial-expiry-warning.blade.php`:

```blade
@if ($du)
Hallo {!! $recipientName !!},

die kostenlose Testphase für „{!! $tenant->name !!}“ endet am {!! $tenant->trial_ends_at->format('d.m.Y') !!} um {!! $tenant->trial_ends_at->format('H:i') !!} Uhr. Danach kannst du dich weiter anmelden; die Verwaltung öffnet sich wieder, sobald ein Tarif gewählt ist.

Damit es nahtlos weitergeht, füll das kurze Formular aus. Wir fragen dort deinen Wunsch-Tarif und deine Rechnungsanschrift ab und melden uns dann bei dir.

Tarif wählen: {!! route('admin.trial.expired') !!}

Fragen beantworten wir gern unter {!! $support !!}.

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

die kostenlose Testphase für „{!! $tenant->name !!}“ endet am {!! $tenant->trial_ends_at->format('d.m.Y') !!} um {!! $tenant->trial_ends_at->format('H:i') !!} Uhr. Danach können Sie sich weiter anmelden; die Verwaltung öffnet sich wieder, sobald ein Tarif gewählt ist.

Damit es nahtlos weitergeht, füllen Sie bitte das kurze Formular aus. Wir fragen dort Ihren Wunsch-Tarif und Ihre Rechnungsanschrift ab und melden uns dann bei Ihnen.

Tarif wählen: {!! route('admin.trial.expired') !!}

Fragen beantworten wir gern unter {!! $support !!}.

Viele Grüße
Ihr Swayy-Team
@endif
```

`resources/views/mail/billing-request-confirm.blade.php`:

```blade
@if ($du)
Hallo {!! $billingRequest->contact_name !!},

danke für deine Anfrage. Bestätige bitte noch deine E-Mail-Adresse, dann geht sie an uns, und dein Konto wird wieder freigeschaltet.

Gewünschter Tarif: {!! $billingRequest->plan_key !!}
Betrieb: {!! $billingRequest->tenant->name !!}

Anfrage bestätigen: {!! $confirmUrl !!}

---

Der Link gilt {!! $hours !!} Stunden. Hast du keine Anfrage gestellt, kannst du diese Mail löschen.
@else
Hallo {!! $billingRequest->contact_name !!},

vielen Dank für Ihre Anfrage. Bitte bestätigen Sie noch Ihre E-Mail-Adresse, dann geht sie an uns, und Ihr Konto wird wieder freigeschaltet.

Gewünschter Tarif: {!! $billingRequest->plan_key !!}
Betrieb: {!! $billingRequest->tenant->name !!}

Anfrage bestätigen: {!! $confirmUrl !!}

---

Der Link gilt {!! $hours !!} Stunden. Haben Sie keine Anfrage gestellt, können Sie diese Mail löschen.
@endif
```

`resources/views/mail/billing-request-owner.blade.php` (intern, ohne Anrede):

```blade
Die Kontaktadresse ist bestätigt. Die Anfrage wartet auf die Freischaltung.

Betrieb: {!! $billingRequest->tenant->name !!}
Betriebs-ID: {!! $billingRequest->tenant_id !!}
Tarif-Wunsch: {!! $billingRequest->plan_key !!}

Name: {!! $billingRequest->contact_name !!}
E-Mail: {!! $billingRequest->contact_email !!}
@if ($billingRequest->phone)
Telefon: {!! $billingRequest->phone !!}
@endif
@if ($billingRequest->company_name)
Firma: {!! $billingRequest->company_name !!}
@endif
Adresse: {!! $billingRequest->address_line1 !!}{!! $billingRequest->address_line2 ? ', '.$billingRequest->address_line2 : '' !!}, {!! $billingRequest->postal_code !!} {!! $billingRequest->city !!}, {!! $billingRequest->country !!}
@if ($billingRequest->vat_id)
USt-ID: {!! $billingRequest->vat_id !!}
@endif
@if ($billingRequest->notes)

Nachricht vom Kunden:
{!! $billingRequest->notes !!}
@endif

Konto freischalten: {!! $activateUrl !!}

Anfrage ansehen: {!! route('admin.billing-requests.index') !!}

---

Eingang: {!! now()->format('d.m.Y H:i') !!} Uhr
```

`resources/views/mail/contact-request.blade.php`:

```blade
Neue Kontaktanfrage über die Swayy-Website.

Name: {!! $senderName !!}
E-Mail: {!! $senderEmail !!}

Nachricht:
{!! $messageText !!}
```

`resources/views/mail/provisioning-welcome.blade.php`:

```blade
@if ($du)
Hallo {!! $recipientName !!},

dein Betrieb „{!! $tenantName !!}“ ist bei Swayy eingerichtet. In Swayy kommst du jederzeit über deinen Kundenbereich: Unter „Verträge“ führt „Zum Dienst“ direkt hinein.

Zum Kundenbereich: {!! $panelUrl !!}

Möchtest du dich auch direkt bei Swayy anmelden, lege dir einmal ein eigenes Passwort fest und melde dich danach an.

Passwort festlegen: {!! $passwordUrl !!}
Zur Anmeldung: {!! $loginUrl !!}
@if ($waitsForApproval)

Deine Verwaltung kannst du schon einrichten. Online-Reservierungen von Gästen nimmt Swayy an, sobald wir deinen Betrieb freigeschaltet haben. Dazu bekommst du eine eigene Mail.
@endif

Tarif, Verträge und Rechnungen findest du im Kundenbereich.

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

Ihr Betrieb „{!! $tenantName !!}“ ist bei Swayy eingerichtet. In Swayy kommen Sie jederzeit über Ihren Kundenbereich: Unter „Verträge“ führt „Zum Dienst“ direkt hinein.

Zum Kundenbereich: {!! $panelUrl !!}

Möchten Sie sich auch direkt bei Swayy anmelden, legen Sie sich einmal ein eigenes Passwort fest und melden sich danach an.

Passwort festlegen: {!! $passwordUrl !!}
Zur Anmeldung: {!! $loginUrl !!}
@if ($waitsForApproval)

Ihre Verwaltung können Sie schon einrichten. Online-Reservierungen von Gästen nimmt Swayy an, sobald wir Ihren Betrieb freigeschaltet haben. Dazu bekommen Sie eine eigene Mail.
@endif

Tarif, Verträge und Rechnungen finden Sie im Kundenbereich.

Viele Grüße
Ihr Swayy-Team
@endif
```

`resources/views/mail/provisioning-approved.blade.php`:

```blade
@if ($du)
Hallo {!! $recipientName !!},

dein Betrieb „{!! $tenantName !!}“ ist freigeschaltet. Ab jetzt nehmen deine Buchungsseiten Online-Reservierungen von Gästen an.

Zur Anmeldung: {!! $loginUrl !!}

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

Ihr Betrieb „{!! $tenantName !!}“ ist freigeschaltet. Ab jetzt nehmen Ihre Buchungsseiten Online-Reservierungen von Gästen an.

Zur Anmeldung: {!! $loginUrl !!}

Viele Grüße
Ihr Swayy-Team
@endif
```

- [ ] **Step 5: Lastschrift-Texte und 72 Stunden**

`BillingRequestController`: `now()->subHours(72)` ersetzen durch `now()->subHours((int) config('swayy.billing_request.confirm_hours', 72))`; im Kommentar bei Zeile 115 „72 Stunden“ durch „die eingestellte Frist (swayy.billing_request.confirm_hours)“ ersetzen.

`DirectDebitController` und `GoCardlessWebhookController`: `use App\Mail\Layout\MailFrameFactory;` ergänzen, vor den Text-Ternären `$du = MailFrameFactory::ownerDu();` setzen und jeden Mailtext (`__("Hallo,\n\n…")`) als du-/Sie-Paar schreiben. Beispiel für „Lastschrift fehlgeschlagen“:

```php
                $du
                    ? __("Hallo,\n\neine SEPA-Lastschrift für „:tenant“ ist fehlgeschlagen. Prüf bitte deine Kontodaten und die Deckung; GoCardless versucht es gegebenenfalls erneut.", ['tenant' => $tenant->name])
                    : __("Guten Tag,\n\neine SEPA-Lastschrift für „:tenant“ ist fehlgeschlagen. Bitte prüfen Sie Ihre Kontodaten und die Deckung; GoCardless versucht es gegebenenfalls erneut.", ['tenant' => $tenant->name]),
```

Die übrigen Texte (Mandat eingerichtet/gekündigt, Zahlung eingegangen, Mandat beendet) gleich behandeln: Inhalt behalten, Anrede an `$du` binden. Bestehende Tests, die einen alten Wortlaut prüfen (`grep -rn "Kontodaten\|wurde gekündigt\|erfolgreich eingezogen" tests`), auf die du-Fassung umstellen (Vorgabe `du`).

- [ ] **Step 6: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='SwayyMailDesignTest|DirectDebit|GoCardless|BillingRequest|PasswordReset|TrialExpiry|Provision|Contact'`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Mail app/Http/Controllers resources/views/mail tests/Feature
git commit -m "feat: Swayy-Mails im neuen Rahmen, Anrede aus der Einstellung (Vorgabe du)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: `mail:vorschau`, Handbuch, Changelog und Prüfung

**Files:**
- Create: `app/Console/Commands/MailPreview.php`
- Modify: `docs/handbuch/06-nachrichten/01-emails.md` (neuer Abschnitt nach „### Platzhalter“), `CHANGELOG.md` (`[Unreleased]`)
- Test: `tests/Feature/MailPreviewCommandTest.php`

**Interfaces:**
- Consumes: alles aus Task 1–10
- Produces: `php artisan mail:vorschau {--ziel=} {--eml}` schreibt je Mailart `<art>.html` und `<art>.txt` (mit `--eml` zusätzlich `<art>.eml`) nach `storage/app/mail-vorschau/`; verschickt nichts.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/MailPreviewCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MailPreviewCommandTest extends TestCase
{
    public function test_preview_writes_every_mail_type(): void
    {
        config(['mail.default' => 'array']);
        $ziel = storage_path('framework/testing/mail-vorschau');
        File::deleteDirectory($ziel);

        $this->artisan('mail:vorschau', ['--ziel' => $ziel, '--eml' => true])->assertSuccessful();

        foreach (['reservation_confirmed', 'reservation_requested', 'payment_pending', 'reservation_table_changed', 'waitlist_offer',
            'event_booking_confirmed', 'guest_verify', 'guest_login', 'marketing', 'operator_new_reservation', 'operator_guest_reply',
            'swayy_password_reset', 'swayy_trial_expiry', 'swayy_billing_confirm', 'swayy_provisioned_welcome', 'swayy_notice'] as $art) {
            $this->assertFileExists("{$ziel}/{$art}.html", $art);
            $this->assertFileExists("{$ziel}/{$art}.txt", $art);
            $this->assertFileExists("{$ziel}/{$art}.eml", $art);
            $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', (string) file_get_contents("{$ziel}/{$art}.html"), $art);
            $this->assertStringContainsString('Content-Type: multipart/alternative', (string) file_get_contents("{$ziel}/{$art}.eml"), $art);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=MailPreviewCommandTest`
Expected: FAIL (`Command "mail:vorschau" is not defined`)

- [ ] **Step 3: Befehl schreiben**

`app/Console/Commands/MailPreview.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\GuestLinkMail;
use App\Mail\Layout\MailFrameFactory;
use App\Mail\PasswordResetMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Mail\TemplatedMail;
use App\Mail\TrialExpiryWarningMail;
use App\Models\Location;
use App\Models\LocationSettings;
use App\Models\Tenant;
use App\Services\NotificationTemplateRenderer;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * Schreibt jede Mailart mit Beispieldaten als HTML und Text (optional .eml)
 * zum Ansehen, für Bildschirmfotos und die Spam-Prüfung. Verschickt nichts
 * und braucht keine Datenbank-Einträge.
 */
class MailPreview extends Command
{
    protected $signature = 'mail:vorschau {--ziel= : Ordner für die Dateien (Vorgabe storage/app/mail-vorschau)} {--eml : zusätzlich vollständige .eml-Dateien schreiben}';

    protected $description = 'Schreibt jede Mailart mit Beispieldaten als Datei, ohne etwas zu verschicken.';

    public function handle(MailFrameFactory $frames): int
    {
        $ziel = (string) ($this->option('ziel') ?: storage_path('app/mail-vorschau'));
        File::ensureDirectoryExists($ziel);

        foreach ($this->samples($frames) as $art => $mail) {
            [$html, $text] = (fn () => $this->renderForAssertions())->call($mail);
            File::put("{$ziel}/{$art}.html", $html);
            File::put("{$ziel}/{$art}.txt", $text);
            if ($this->option('eml')) {
                File::put("{$ziel}/{$art}.eml", $this->eml($mail));
            }
            $this->line("{$art}: geschrieben");
        }

        $this->info("Fertig: {$ziel}");

        return self::SUCCESS;
    }

    /** @return array<string, Mailable> */
    private function samples(MailFrameFactory $frames): array
    {
        [$tenant, $du] = $this->demo('du');
        [$tenantSie, $sie] = $this->demo('Sie');
        $vars = [
            '{guest_name}' => 'Testgast Claude', '{reservation_date}' => '24.11.2026', '{reservation_time}' => '18:00',
            '{party_size}' => '2', '{location_name}' => 'Trattoria Luna', '{reservation_code}' => 'R-CIHHL2',
            '{cancel_link}' => url('/reservation/R-CIHHL2/manage/beispiel'), '{payment_link}' => url('/pay/R-CIHHL2/beispiel'),
            '{payment_amount}' => '20,00 EUR', '{payment_deadline}' => '20.11.2026 18:00', '{waitlist_link}' => url('/warteliste/1/beispiel'),
            '{feedback_link}' => url('/feedback/beispiel'), '{custom_message}' => '', '{table_name}' => 'Tisch 7',
        ];
        $vorlage = function (string $key, string $address, Tenant $t, Location $l) use ($vars, $frames): TemplatedMail {
            $tpl = NotificationTemplateRenderer::defaults($address)[$key];
            $body = strtr(NotificationTemplateRenderer::dropEmptyLines($tpl['body'], array_combine(array_map(fn ($k) => trim($k, '{}'), array_keys($vars)), $vars)), $vars);

            return new TemplatedMail(strtr($tpl['subject'], $vars), $body, 'Trattoria Luna', null, $frames->forGuest($key, $t, $l));
        };
        $unsubscribe = url('/marketing/abmelden/1?signature=beispiel');

        return [
            'reservation_confirmed' => $vorlage('reservation_confirmed', 'du', $tenant, $du),
            'reservation_requested' => $vorlage('reservation_requested', 'Sie', $tenantSie, $sie),
            'payment_pending' => $vorlage('payment_pending', 'du', $tenant, $du),
            'waitlist_offer' => $vorlage('waitlist_offer', 'du', $tenant, $du),
            'reservation_table_changed' => new TemplatedMail('Tischänderung zu deiner Reservierung R-CIHHL2 – Trattoria Luna',
                "Hallo Testgast Claude,\n\nwir haben dir einen anderen Tisch zugewiesen, damit alles gut passt. Sonst bleibt deine Reservierung, wie sie ist.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nTisch: Tisch 9\nReservierungsnummer: R-CIHHL2\n\nBei Fragen melde dich gern.\nTrattoria Luna",
                'Trattoria Luna', null, $frames->forGuest('reservation_table_changed', $tenant, $du)),
            'event_booking_confirmed' => new TemplatedMail('Buchungsbestätigung: Trüffelabend',
                "Hallo Testgast Claude,\n\nfür „Trüffelabend“ ist alles gebucht. Wir freuen uns auf dich.\n\nDatum: 05.12.2026\nUhrzeit: 19:00 Uhr\nTickets: 2\nBuchungsnummer: E-4711\nBetrag: 89,00 EUR\n\nJetzt bezahlen: ".url('/pay/event/E-4711/beispiel')."\n\nDie Vorauszahlung verrechnen wir bei deinem Besuch vollständig mit der Rechnung. Bei Nichterscheinen behalten wir sie ein.\n\nBuchung ansehen oder stornieren: ".url('/events/E-4711/beispiel')."\n\nTrattoria Luna",
                'Trattoria Luna', null, $frames->forGuest('event_booking_confirmed', $tenant, $du)),
            'guest_verify' => new GuestLinkMail('Bitte bestätige deine E-Mail-Adresse', "Hallo Testgast Claude,\n\nein Klick noch, dann ist deine Buchung R-CIHHL2 bei uns.",
                url('/konto/verify/beispiel'), 'E-Mail-Adresse bestätigen', 'Trattoria Luna', null, $frames->forGuest('guest_verify', $tenant, $du),
                'Der Link gilt 24 Stunden. Hast du nicht bei uns reserviert, kannst du diese Mail löschen.'),
            'guest_login' => new GuestLinkMail('Ihr Anmeldelink für Trattoria Luna', "Hallo Testgast Claude,\n\nhier ist Ihr Anmeldelink für Ihr Kundenkonto bei Trattoria Luna.",
                url('/konto/luna/login/beispiel'), 'Jetzt anmelden', 'Trattoria Luna', null, $frames->forGuest('guest_login', $tenantSie, $sie),
                'Der Link gilt 1 Stunde. Haben Sie ihn nicht angefordert, können Sie diese Mail löschen.'),
            'marketing' => new TemplatedMail('Neue Karte ab Freitag', "Hallo Testgast Claude,\n\nab Freitag gibt es Trüffel aus dem Piemont.\n\nTisch reservieren: ".url('/book/luna')."\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$unsubscribe}",
                'Trattoria Luna', null, $frames->forGuest('marketing', $tenant, $du, [], $unsubscribe)),
            'operator_new_reservation' => new TemplatedMail('Neue Reservierung – 24.11. 18:00 · Testgast Claude (2 P.)',
                "Eine neue Reservierung ist eingegangen. Sie wartet auf deine Bestätigung.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nPersonen: 2\nTisch: Tisch 7\nStatus: Angefragt\nReservierungsnummer: R-CIHHL2\n\nGast: Testgast Claude\nE-Mail: claude-testbuchung@example.test\nTelefon: +49 151 00000000\nQuelle: Online\nGastnotiz: Fensterplatz, wenn möglich\n\nReservierung öffnen: ".url('/admin/reservations/1'),
                null, null, $frames->forSwayy('operator_new_reservation', ['location' => 'Trattoria Luna'])),
            'operator_guest_reply' => new TemplatedMail('Antwort von Testgast Claude: Allergie',
                "Antwort zur Buchung R-CIHHL2 vom 24.11.2026:\n\nHallo, ich habe eine Nussallergie.\nViele Grüße",
                null, null, $frames->forSwayy('operator_guest_reply', ['guest' => 'Testgast Claude', 'location' => 'Trattoria Luna'], structured: false)),
            'swayy_password_reset' => new PasswordResetMail(url('/passwort/neu/beispiel')),
            'swayy_trial_expiry' => new TrialExpiryWarningMail(tap(new Tenant(['name' => 'Trattoria Luna']), fn (Tenant $t) => $t->trial_ends_at = now()->addDays(5)), 'Olivia'),
            'swayy_billing_confirm' => $this->billingConfirm(),
            'swayy_provisioned_welcome' => new ProvisionedWelcomeMail('Olivia', 'Trattoria Luna', true, url('/passwort'), url('/login'), (string) (config('provisioning.panel_base_url') ?: url('/'))),
            'swayy_notice' => new TemplatedMail('Lastschrift eingerichtet – Trattoria Luna', "Hallo,\n\ndas SEPA-Lastschriftmandat für „Trattoria Luna“ ist eingerichtet."),
        ];
    }

    private function billingConfirm(): Mailable
    {
        $request = new \App\Models\BillingRequest(['contact_name' => 'Olivia Owner', 'plan_key' => 'starter', 'token' => 'beispiel']);
        $request->setRelation('tenant', new Tenant(['name' => 'Trattoria Luna']));

        return new \App\Mail\BillingRequestConfirmMail($request);
    }

    /** @return array{0: Tenant, 1: Location} */
    private function demo(string $address): array
    {
        $tenant = new Tenant(['name' => 'Trattoria Luna GmbH', 'imprint_url' => url('/impressum-beispiel'), 'privacy_url' => url('/datenschutz-beispiel')]);
        $location = new Location([
            'name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115', 'city' => 'Berlin',
            'phone' => '030 1234567', 'email' => 'luna@example.test',
        ]);
        $location->setRelation('tenant', $tenant);
        $location->setRelation('settings', new LocationSettings(['guest_address' => $address]));

        return [$tenant, $location];
    }

    private function eml(Mailable $mail): string
    {
        $mail->to('gast@example.test');
        Mail::mailer('array')->send($mail);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        return $messages->last()->toString();
    }
}
```

Hinweise für die Umsetzung: `Location::effectiveSettings()` muss mit der gesetzten Relation `settings` arbeiten; liest es aus der Datenbank, in `demo()` stattdessen die Methode `effectiveSettings` per Relation nachbilden, wie sie es braucht (Code in `app/Models/Location.php` lesen). `BillingRequestConfirmMail` erzeugt im Konstruktor `route('billing.confirm', …)`; das klappt ohne Datenbank. `eml()` schickt nur über den Mailer `array` (Speicher), nie über den eingestellten Versandweg.

- [ ] **Step 4: Test laufen lassen, er besteht**

Run: `PAO_DISABLE=1 php artisan test --filter=MailPreviewCommandTest`
Expected: PASS

- [ ] **Step 5: Handbuch und Changelog**

In `docs/handbuch/06-nachrichten/01-emails.md` nach dem Abschnitt „### Platzhalter“ einfügen:

```markdown
### So wird aus deinem Text eine gestaltete Mail

Du schreibst einfachen Text. Swayy gestaltet ihn beim Versand:

- Mehrere Zeilen in der Form „Bezeichnung: Wert“ direkt untereinander, etwa
  „Datum: {reservation_date}“ und „Uhrzeit: {reservation_time} Uhr“, werden zu
  einer Tabelle.
- Eine Zeile „Beschriftung: {cancel_link}“ wird zu einem Knopf mit dieser
  Beschriftung. Der erste Knopf ist hervorgehoben.
- Eine Zeile aus drei Bindestrichen (`---`) setzt eine Trennlinie; Text darunter
  erscheint klein, gut für Hinweise.
- Bleibt ein Platzhalter leer, etwa der Zahlungslink ohne offene Zahlung,
  entfällt seine Zeile ganz.

Oben steht eine Überschrift, die Swayy je Mail setzt, unten der Fußbereich mit
Adresse, Telefon, E-Mail, Impressum und Datenschutz deines Standorts. Pflege
diese Angaben unter Einstellungen › Standorte und Einstellungen › Allgemein,
dann erscheinen sie in jeder Mail. Logo und Markenfarbe übernimmt Swayy ebenfalls.
```

In `CHANGELOG.md` unter `## [Unreleased]` ergänzen:

```markdown
### Neues Mail-Design

- Alle Mails haben einen gemeinsamen, hochwertigen Rahmen: Überschrift je
  Mailart, Angaben als Tabelle, Knopf statt langer Links, Fußbereich mit
  Adresse, Kontakt, Impressum und Datenschutz, Dunkelmodus, gut lesbar auf dem
  Handy. Gast-Mails tragen Name, Logo und Markenfarbe des Betriebs; Mails an
  Betriebe und von Swayy den Swayy-Auftritt.
- Bestätigungs- und Anmeldelink für Gäste sowie alle Swayy-Mails kommen jetzt
  mit HTML-Teil; nichts wird von außen geladen.
- Kampagnen-Mails tragen `List-Unsubscribe` mit Abmeldung per Klick (RFC 8058).
- Neue Einstellungen `SWAYY_MAIL_*` (Anrede der Swayy-Mails, Vorgabe du),
  `SWAYY_GUEST_LOGIN_LINK_MINUTES`, `SWAYY_GUEST_VERIFY_LINK_MINUTES`,
  `SWAYY_BILLING_CONFIRM_HOURS`.
```

- [ ] **Step 6: Sichtprüfung**

```bash
PAO_DISABLE=1 php artisan mail:vorschau --eml
```

Für `reservation_confirmed`, `guest_verify`, `operator_new_reservation`, `swayy_password_reset` und `marketing` Bildschirmfotos hell (Desktop 760 px), dunkel und bei 390 px Breite mit Edge headless (`--blink-settings=preferredColorScheme=1` hell, `=0` dunkel; für 390 px die Datei in einem 390 px breiten `iframe` laden, weil Edge headless kein schmaleres Fenster erlaubt). Mit dem freigegebenen Entwurf vom 10.10.2026 vergleichen: Kopf, Überschrift, getönte Tabelle, Knopf, Fußbereich. Abweichungen in einem Durchgang beheben, einmal nachprüfen.

- [ ] **Step 7: Spam-Prüfung**

Für jede `.eml` aus `storage/app/mail-vorschau/` (Bash):

```bash
for f in storage/app/mail-vorschau/*.eml; do echo "== $f"; docker run --rm -i debian:stable-slim sh -c 'apt-get update -qq >/dev/null && apt-get install -y -qq spamassassin >/dev/null 2>&1 && spamassassin -t -L' < "$f" | grep -E "^Content analysis|^ *-?[0-9.]+ [A-Z_]+|X-Spam-Status"; done
```

Expected: je Mail `score` unter 1.0. Regeln, die nur an der fehlenden Zustellung hängen (z. B. fehlende DKIM-Signatur im lokalen Abzug), notieren; inhaltliche Treffer (HTML-Anteil, Bild-Anteil, Großbuchstaben, Linktexte) beheben und neu prüfen.

- [ ] **Step 8: Gesamte Prüfung**

Run: `PAO_DISABLE=1 php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run build`
Expected: alles grün.

- [ ] **Step 9: Commit**

```bash
git add app/Console/Commands/MailPreview.php tests/Feature/MailPreviewCommandTest.php docs/handbuch/06-nachrichten/01-emails.md CHANGELOG.md
git commit -m "feat: mail:vorschau, Handbuch und Changelog zum neuen Mail-Design

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

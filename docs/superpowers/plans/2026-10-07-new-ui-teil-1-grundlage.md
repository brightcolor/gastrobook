# Neue Oberfläche, Teil 1: Grundlage, Navigation, erste Einstellungsseite – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Verwaltung bekommt die neue Navigation (Tagesgeschäft in Gruppen, eigener Einstellungsbereich, Symbolleiste am Tablet, untere Leiste mit „Mehr“ auf dem Smartphone), die Seitenbausteine der Grundlage und als erste neue Einstellungsseite „Allgemein“.

**Architecture:** `config/navigation.php` beschreibt Haupt- und Einstellungsnavigation; `App\Support\Navigation\Navigation` filtert nach Rechten und Betriebstyp und liefert typisierte Gruppen an das Layout. Blade-Bausteine (`x-page-header`, `x-settings.section`, `x-field`, `x-save-bar`) und ES-Module unter `resources/js/admin/` setzen die Seitenmuster um. Noch nicht umgezogene Einstellungen verlinkt die Einstellungsnavigation übergangsweise auf die Reiter der alten Sammelseite (`/admin/settings#…`); Teil 2 ersetzt diese Verweise Seite für Seite.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Tailwind CSS 4 (Vite), Vanilla-JS als ES-Module, PHPUnit, Pint, PHPStan (Larastan Level 2).

**Spec:** `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`

## Gesamtfahrplan (A+B)

| Teil | Inhalt | Plan |
|---|---|---|
| 1 | Arbeitsumgebung, UI-Werte, Navigation, Layout, Einstellungs-Menü, Bausteine, Formular- und Speicherleisten-Skripte, Seite „Allgemein“, Prüfung | **dieser** |
| 2 | Übrige Einstellungsseiten: Standorte (mit Profil), Team & Rollen, Abo & Rechnungen, Protokoll, Daten (aus „Mein Konto“), Buchungsregeln, Kapazität & Dauer, Benachrichtigungen (mit Anrede), Buchungsformular, Buchungsseite & Widget, Tags, Vorlagen, Absender & Antworten, SMS-Versand, Zahlungsanbieter, Anzahlungen, Newsletter, API-Schlüssel, Webhooks, Räume & Tische, Leistungen, Mitarbeiter; Bausteine `x-drawer`, `x-inline-confirm`, `x-empty-state`, `x-status-icon` | eigener Plan, nach Abschluss von Teil 1 |
| 3 | Öffnungszeiten (Woche, Übertragen, Saison), Vorschau-Endpunkt, `exception_group`, Ausnahmen, „Sperren…“ am Tisch, Abbau der Sammelseite, Zuordnungstest, Weiterleitungen, Abnahmeprüfung | eigener Plan, nach Abschluss von Teil 2 |

Teil 2 und 3 werden geschrieben, wenn Teil 1 auf `new-ui` liegt: Ihr Code nutzt die Schnittstellen dieser Bausteine (Klassennamen, Props, Daten-Attribute, Events) genau so, wie Teil 1 sie festlegt.

## Global Constraints

- Farben, Schriften und Tokens von Swayy bleiben (Stein-Palette, Teal-Akzent `--brand`, Inter). Kein Gestaltungsschema von `bright-color-apps`.
- Rechte unverändert: `config/permissions.php` wird nicht geändert; jede Route behält ihre `permission:`-Middleware.
- Code und Bezeichner englisch, Oberflächentexte deutsch mit echten Umlauten, Anrede „du“ wie im übrigen Admin.
- Oberflächentexte ohne Negativabgrenzungen („X statt Y“, „kein X, sondern Y“); eine Verneinung nur, wenn sie selbst die Information ist.
- Fehlermeldungen nennen Ursache und nächsten Schritt.
- Nichts fest im Code: Verhaltenswerte stehen mit Vorgabe und Grenzen in `config/swayy.php` (über `App\Support\EnvSetting`), die Navigation in `config/navigation.php`. Tests prüfen auch mit anderen Werten als der Vorgabe.
- Neuer Code nutzt keine `onclick`/`onchange`/`onsubmit`-Attribute.
- Barrierefreiheit WCAG 2.2 AA: sichtbarer Fokus, Klickziele ≥ 24 × 24 px, `aria-current="page"`, Live-Region für Hinweise.
- Tests: `declare(strict_types=1)`, `use CreatesTenants, RefreshDatabase;`, vor Requests `$this->clearTenantContext()`. Keine echten Namen oder Adressen in Testdaten.
- PHP: Imports alphabetisch, keine vollqualifizierten Klassennamen in Code oder Docblocks (Pint `fully_qualified_strict_types`).
- Arbeitskopie: Worktree `C:\Users\brigh\Claude Workingdir\gastrobook-new-ui`, Branch `new-ui` ohne Upstream. Nie auf `main` pushen (Watchtower rollt `main` sofort aus). Push von `new-ui` und Entwurfs-PR erst nach Mathias' Freigabe.
- Befehle laufen in Git Bash im Worktree. Tests mit `PAO_DISABLE=1 php artisan test …` (sonst gibt laravel/pao JSON aus).
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Arbeitsumgebung im Worktree

**Files:**
- Modify: `.claude/launch.json`
- Create (nicht versioniert): `.env`, `database/database.sqlite`, `vendor/`, `node_modules/`, `public/build/`

**Interfaces:**
- Consumes: –
- Produces: lauffähiger Worktree mit grüner Ausgangs-Testsuite; Startkonfiguration `gastrobook-new-ui` auf Port 8010 für `preview_start`.

- [ ] **Step 1: Abhängigkeiten installieren**

Run (im Worktree):
```bash
php C:/Users/brigh/composer.phar install --no-interaction --prefer-dist
npm ci
```
Expected: beide ohne Fehler. `vendor/` wird installiert, nicht von der Hauptkopie verknüpft (eine Verknüpfung lädt die Tests der Hauptkopie).

- [ ] **Step 2: `.env` für den lokalen Lauf anlegen**

```bash
cp .env.example .env
php artisan key:generate
```

Danach in `.env` diese Werte setzen (bestehende Zeilen ersetzen):
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8010
DB_CONNECTION=sqlite
DB_DATABASE="C:/Users/brigh/Claude Workingdir/gastrobook-new-ui/database/database.sqlite"
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=false
CACHE_STORE=file
QUEUE_CONNECTION=sync
SWAYY_BOARD_SSE=false
```

- [ ] **Step 3: Datenbank mit Demodaten**

```bash
touch database/database.sqlite
php artisan migrate --seed --no-interaction
```
Expected: Migrationen und `DatabaseSeeder` laufen durch. Demo-Zugänge aus dem Seeder: `owner@demo.test` und `host@demo.test`, Passwort `password` (nur lokal).

- [ ] **Step 4: Ausgangslage der Tests festhalten**

Run: `PAO_DISABLE=1 php artisan test`
Expected: alle Tests grün. Anzahl der Tests notieren (Vergleich in Task 9).

- [ ] **Step 5: Startkonfiguration für die Vorschau ergänzen**

`.claude/launch.json` bekommt einen zweiten Eintrag (bestehenden Eintrag `gastrobook` unverändert lassen):
```json
{
  "version": "0.0.1",
  "configurations": [
    {
      "name": "gastrobook",
      "runtimeExecutable": "php",
      "runtimeArgs": ["artisan", "serve", "--port=8000"],
      "port": 8000
    },
    {
      "name": "gastrobook-new-ui",
      "runtimeExecutable": "php",
      "runtimeArgs": ["artisan", "serve", "--port=8010"],
      "port": 8010
    }
  ]
}
```
Port 8000 ist auf diesem Rechner belegt; 8010 ist frei zu halten.

- [ ] **Step 6: Build prüfen**

Run: `npm run build`
Expected: Build ohne Fehler, `public/build/manifest.json` vorhanden.

- [ ] **Step 7: Commit**

```bash
git add .claude/launch.json
git commit -m "chore: Startkonfiguration für den new-ui-Worktree (Port 8010)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: UI-Werte in `config/swayy.php`

**Files:**
- Modify: `app/Support/EnvSetting.php` (neue Methode `hexColor`)
- Modify: `config/swayy.php` (neuer Abschnitt `ui`)
- Modify: `.env.example` (Variablen dokumentieren)
- Modify: `resources/views/layouts/admin.blade.php` (Werte an den Browser geben)
- Test: `tests/Unit/EnvSettingTest.php`, `tests/Feature/UiSettingsTest.php`

**Interfaces:**
- Consumes: `EnvSetting::raw()`, `EnvSetting::warn()` (privat, bestehend)
- Produces: `EnvSetting::hexColor(string $variable, string $default): string`; `config('swayy.ui.toast_ms')` (int, Vorgabe 3200, 1000–20000); `config('swayy.ui.default_brand_color')` (string `#rrggbb`, Vorgabe `#0f766e`); im Browser `window.SwayyAdmin = { ui: { toastMs }, problemTexts }`.

- [ ] **Step 1: Failing tests schreiben**

In `tests/Unit/EnvSettingTest.php` vor `private function capture` einfügen:
```php
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
```

Neue Datei `tests/Feature/UiSettingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UiSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(3200, config('swayy.ui.toast_ms'));
        $this->assertSame('#0f766e', config('swayy.ui.default_brand_color'));
    }

    public function test_layout_hands_the_toast_duration_to_the_browser(): void
    {
        config(['swayy.ui.toast_ms' => 5000]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin')
            ->assertOk()
            ->assertSee('"toastMs":5000', false)
            ->assertSee('"network":', false);
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie schlagen fehl**

Run: `PAO_DISABLE=1 php artisan test --filter='EnvSettingTest|UiSettingsTest'`
Expected: FAIL – `Call to undefined method App\Support\EnvSetting::hexColor()` und fehlende Konfiguration.

- [ ] **Step 3: `EnvSetting::hexColor` ergänzen**

In `app/Support/EnvSetting.php` nach `integer()` einfügen:
```php
    /**
     * Farbe in der Schreibweise #rrggbb, kleingeschrieben.
     */
    public static function hexColor(string $variable, string $default): string
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $raw) !== 1) {
            self::warn("{$variable}=„{$raw}“ ist keine Farbe in der Schreibweise #rrggbb. Es gilt die Vorgabe {$default}. Bitte einen Wert wie #0f766e in der .env eintragen.");

            return $default;
        }

        return strtolower($raw);
    }
```

- [ ] **Step 4: Abschnitt `ui` in `config/swayy.php`**

Vor der schließenden `];` der Datei einfügen:
```php
    /*
    |--------------------------------------------------------------------------
    | Oberfläche der Verwaltung
    |--------------------------------------------------------------------------
    |
    | toast_ms: wie lange ein Hinweis wie „Gespeichert“ stehen bleibt, in
    | Millisekunden. default_brand_color: Markenfarbe, solange ein Betrieb
    | keine eigene gewählt hat (Buchungsseite, Buchungsknopf, Farbwahl in den
    | Einstellungen).
    |
    */
    'ui' => [
        'toast_ms' => EnvSetting::integer('SWAYY_UI_TOAST_MS', default: 3200, min: 1000, max: 20000),
        'default_brand_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_BRAND_COLOR', default: '#0f766e'),
    ],
```

In `.env.example` am Ende ergänzen:
```dotenv
# Oberfläche der Verwaltung
# Anzeigedauer von Hinweisen wie „Gespeichert“ in Millisekunden (1000–20000)
# SWAYY_UI_TOAST_MS=3200
# Markenfarbe, solange ein Betrieb keine eigene gewählt hat (#rrggbb)
# SWAYY_UI_DEFAULT_BRAND_COLOR=#0f766e
```

- [ ] **Step 5: Werte im Layout an den Browser geben**

In `resources/views/layouts/admin.blade.php` direkt vor `@vite([...])` einfügen:
```blade
    <script>
        window.SwayyAdmin = @json([
            'ui' => [
                'toastMs' => config('swayy.ui.toast_ms'),
            ],
            'problemTexts' => \App\Support\AdminJsonErrors::forClient(),
        ]);
    </script>
```

- [ ] **Step 6: Tests laufen lassen, sie sind grün**

Run: `PAO_DISABLE=1 php artisan test --filter='EnvSettingTest|UiSettingsTest'`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Support/EnvSetting.php config/swayy.php tests/Unit/EnvSettingTest.php tests/Feature/UiSettingsTest.php
git add app/Support/EnvSetting.php config/swayy.php .env.example resources/views/layouts/admin.blade.php tests/Unit/EnvSettingTest.php tests/Feature/UiSettingsTest.php
git commit -m "feat: Oberflächenwerte der Verwaltung in config/swayy.php

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Navigation aus einer Quelle

**Files:**
- Create: `config/navigation.php`
- Create: `app/Support/Navigation/Navigation.php`
- Create: `app/Support/Navigation/NavGroup.php`
- Create: `app/Support/Navigation/NavItem.php`
- Test: `tests/Feature/NavigationTest.php`

**Interfaces:**
- Consumes: `User::canInTenant(string $permission, Tenant $tenant, ?Location $location): bool`, `Tenant::isSalon(): bool`, `route()`.
- Produces:
  - `Navigation::fromConfig(): Navigation`
  - `Navigation::main(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): list<NavGroup>`
  - `Navigation::settings(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): list<NavGroup>`
  - `Navigation::mobileBar(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): list<NavItem>`
  - `Navigation::firstSettingsUrl(User $user, Tenant $tenant, ?Location $location): ?string`
  - `Navigation::isSettingsRoute(?string $currentRoute): bool`
  - `NavGroup { public ?string $label; public array $items }` (`$label` ist `null`, wenn weniger als zwei Einträge sichtbar sind)
  - `NavItem { public string $key, $label, $url, $icon; public bool $active; public ?string $scope; public array $keywords }`
  - Schlüssel der Einträge (für Tests und `data-nav-key`): Haupt `dashboard, board, floorplan, staff-calendar, walkins, waitlist, reservations, events, refunds, guests, marketing, reports`; Einstellungen `general, locations, team, billing, audit, data, opening-hours, exceptions, rooms, services, staff, capacity, booking-rules, booking-form, booking-page, tags, notifications, templates, sender, sms, payment-providers, deposits, newsletter, api, webhooks`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/NavigationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Support\Navigation\NavGroup;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  list<NavGroup>  $groups
     * @return list<array{0: ?string, 1: list<string>}>
     */
    private function shape(array $groups): array
    {
        return array_map(
            fn (NavGroup $group) => [$group->label, array_map(fn (NavItem $item) => $item->key, $group->items)],
            $groups,
        );
    }

    public function test_owner_sees_the_grouped_main_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $groups = Navigation::fromConfig()->main($owner, $setup['tenant'], $setup['location'], 'admin.board');

        $this->assertSame([
            [null, ['dashboard']],
            ['Service', ['board', 'floorplan', 'walkins', 'waitlist']],
            ['Buchungen', ['reservations', 'events', 'refunds']],
            ['Gäste', ['guests', 'marketing']],
            [null, ['reports']],
        ], $this->shape($groups));
        $this->assertTrue($groups[1]->items[0]->active);
        $this->assertFalse($groups[1]->items[1]->active);
    }

    public function test_a_heading_needs_two_visible_items(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');

        $groups = Navigation::fromConfig()->main($host, $setup['tenant'], $setup['location'], null);

        $this->assertSame([
            [null, ['dashboard']],
            ['Service', ['board', 'floorplan', 'walkins', 'waitlist']],
            [null, ['reservations']],
            [null, ['guests']],
        ], $this->shape($groups));
    }

    public function test_salon_gets_its_own_items_and_labels(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $groups = Navigation::fromConfig()->main($owner, $setup['tenant']->refresh(), $setup['location'], null);
        $labels = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                $labels[$item->key] = $item->label;
            }
        }

        $this->assertArrayHasKey('staff-calendar', $labels);
        $this->assertArrayNotHasKey('floorplan', $labels);
        $this->assertSame('Termine', $labels['reservations']);
        $this->assertSame('Kunden', $labels['guests']);
    }

    public function test_mobile_bar_holds_the_four_daily_destinations(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $items = Navigation::fromConfig()->mobileBar($owner, $setup['tenant'], $setup['location'], null);

        $this->assertSame(['dashboard', 'board', 'reservations', 'guests'], array_map(fn (NavItem $item) => $item->key, $items));
    }

    public function test_settings_are_grouped_and_filtered_by_permission(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = Navigation::fromConfig();

        $shape = $this->shape($nav->settings($owner, $setup['tenant'], $setup['location'], null));

        $this->assertSame(
            ['Betrieb', 'Platz & Zeiten', 'Buchungen', 'Gästekommunikation', 'Zahlungen', 'Verbindungen'],
            array_column($shape, 0),
        );
        $this->assertSame(['opening-hours', 'exceptions', 'rooms', 'capacity'], $shape[1][1]);
        $this->assertSame(route('admin.settings.index').'#allgemein', $nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }

    public function test_settings_entry_follows_the_first_allowed_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $nav = Navigation::fromConfig();

        $this->assertSame(route('admin.settings.index').'#zeiten', $nav->firstSettingsUrl($manager, $setup['tenant'], $setup['location']));
        $this->assertSame([], $nav->settings($staff, $setup['tenant'], $setup['location'], null));
        $this->assertNull($nav->firstSettingsUrl($staff, $setup['tenant'], $setup['location']));
    }

    public function test_settings_routes_are_recognised(): void
    {
        $nav = Navigation::fromConfig();

        $this->assertTrue($nav->isSettingsRoute('admin.users.index'));
        $this->assertTrue($nav->isSettingsRoute('admin.settings.index'));
        $this->assertTrue($nav->isSettingsRoute('admin.billing.show'));
        $this->assertFalse($nav->isSettingsRoute('admin.dashboard'));
        $this->assertFalse($nav->isSettingsRoute('admin.account.show'));
        $this->assertFalse($nav->isSettingsRoute(null));
    }

    public function test_rules_follow_a_different_config(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $nav = new Navigation([
            'mobile_bar_max' => 1,
            'main' => [
                ['label' => 'Gruppe', 'items' => [
                    ['key' => 'a', 'label' => 'A', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
                    ['key' => 'b', 'label' => 'B', 'route' => 'admin.board', 'icon' => 'board', 'only' => 'salon', 'mobile_bar' => true],
                    ['key' => 'c', 'label' => 'C', 'route' => 'admin.reports.index', 'icon' => 'reports', 'can_any' => ['marketing.manage', 'audit.view']],
                ]],
            ],
            'settings' => [],
        ]);

        $this->assertSame([['Gruppe', ['a', 'c']]], $this->shape($nav->main($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([[null, ['a']]], $this->shape($nav->main($host, $setup['tenant'], $setup['location'], null)));
        $this->assertCount(1, $nav->mobileBar($owner, $setup['tenant'], $setup['location'], null));
        $this->assertNull($nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=NavigationTest`
Expected: FAIL – `Class "App\Support\Navigation\Navigation" not found`.

- [ ] **Step 3: Werteklassen anlegen**

`app/Support/Navigation/NavItem.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Ein sichtbarer Eintrag der Navigation, fertig für die Ansicht.
 */
final readonly class NavItem
{
    /**
     * @param  list<string>  $keywords  Suchbegriffe (nur Einstellungen)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public string $icon,
        public bool $active,
        public ?string $scope,
        public array $keywords,
    ) {}
}
```

`app/Support/Navigation/NavGroup.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Gruppe der Navigation. $label ist null, wenn weniger als zwei Einträge
 * sichtbar sind: Eine Überschrift über einem einzelnen Eintrag ordnet nichts.
 */
final readonly class NavGroup
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public ?string $label,
        public array $items,
    ) {}
}
```

- [ ] **Step 4: `Navigation` anlegen**

`app/Support/Navigation/Navigation.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Baut die Navigation der Verwaltung aus config/navigation.php.
 *
 * Filtert nach Rechten und Betriebstyp und wendet die Regel an, dass eine
 * Gruppenüberschrift erst ab zwei sichtbaren Einträgen erscheint.
 */
final class Navigation
{
    /** Routen, die immer zum Einstellungsbereich gehören. */
    private const SETTINGS_ROUTES = 'admin.settings.*';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('navigation', []));
    }

    /**
     * @return list<NavGroup>
     */
    public function main(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['main'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavGroup>
     */
    public function settings(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['settings'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavItem>
     */
    public function mobileBar(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        $items = [];
        foreach ((array) ($this->config['main'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (($item['mobile_bar'] ?? false) === true && $this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }
        }

        return array_slice($items, 0, (int) ($this->config['mobile_bar_max'] ?? 4));
    }

    public function firstSettingsUrl(User $user, Tenant $tenant, ?Location $location): ?string
    {
        foreach ($this->settings($user, $tenant, $location, null) as $group) {
            foreach ($group->items as $item) {
                return $item->url;
            }
        }

        return null;
    }

    public function isSettingsRoute(?string $currentRoute): bool
    {
        if ($currentRoute === null) {
            return false;
        }

        if (Str::is(self::SETTINGS_ROUTES, $currentRoute)) {
            return true;
        }

        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (Str::is($this->activePatterns($item), $currentRoute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<NavGroup>
     */
    private function groups(array $groups, User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        $result = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }

            if ($items === []) {
                continue;
            }

            $label = count($items) >= 2 ? ($group['label'] ?? null) : null;
            $result[] = new NavGroup($label, $items);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function visible(array $item, User $user, Tenant $tenant, ?Location $location): bool
    {
        $only = $item['only'] ?? null;
        if ($only === 'salon' && ! $tenant->isSalon()) {
            return false;
        }
        if ($only === 'restaurant' && $tenant->isSalon()) {
            return false;
        }

        foreach ((array) ($item['can'] ?? []) as $permission) {
            if (! $user->canInTenant((string) $permission, $tenant, $location)) {
                return false;
            }
        }

        $any = (array) ($item['can_any'] ?? []);
        if ($any === []) {
            return true;
        }

        foreach ($any as $permission) {
            if ($user->canInTenant((string) $permission, $tenant, $location)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(array $item, Tenant $tenant, ?string $currentRoute): NavItem
    {
        $label = $tenant->isSalon() && isset($item['label_salon']) ? $item['label_salon'] : $item['label'];
        $url = route((string) $item['route']).(isset($item['fragment']) ? '#'.$item['fragment'] : '');

        return new NavItem(
            key: (string) $item['key'],
            label: (string) $label,
            url: $url,
            icon: (string) ($item['icon'] ?? ''),
            active: $currentRoute !== null && Str::is($this->activePatterns($item), $currentRoute),
            scope: isset($item['scope']) ? (string) $item['scope'] : null,
            keywords: array_values(array_map('strval', (array) ($item['keywords'] ?? []))),
        );
    }

    /**
     * Routenmuster, bei denen ein Eintrag als aktuell gilt. Vorgabe ist die
     * eigene Route; eine leere Liste heißt: nie (Verweise auf Sprungmarken).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function activePatterns(array $item): array
    {
        return array_values(array_map('strval', (array) ($item['active'] ?? [$item['route']])));
    }
}
```

- [ ] **Step 5: `config/navigation.php` anlegen**

Noch nicht umgezogene Einstellungen verweisen auf die Reiter der alten Sammelseite (`admin.settings.index` + `fragment`). Diese Route verlangt `tables.manage`; deshalb tragen solche Einträge `tables.manage` in `can`.

```php
<?php

/*
|--------------------------------------------------------------------------
| Navigation der Verwaltung
|--------------------------------------------------------------------------
|
| Eine Quelle für Seitenleiste, Symbolleiste (Tablet), untere Leiste
| (Smartphone), „Mehr“ und den Einstellungsbereich. App\Support\Navigation
| liest diese Datei, filtert nach Rechten und Betriebstyp und wendet die
| Regel an: Eine Gruppenüberschrift erscheint erst ab zwei sichtbaren
| Einträgen.
|
| Felder je Eintrag:
|   key         eindeutiger Schlüssel (Tests, data-nav-key)
|   label       Bezeichnung; label_salon ersetzt sie bei Salon-Betrieben
|   route       Routenname; fragment hängt #… an
|   icon        Name aus resources/views/components/nav-icon.blade.php
|   can         Rechte, die alle vorliegen müssen
|   can_any     Rechte, von denen mindestens eines vorliegen muss
|   only        'restaurant' oder 'salon'; ohne Angabe für beide
|   active      Routenmuster, bei denen der Eintrag als aktuell gilt
|               (Vorgabe: die eigene Route; [] = nie)
|   mobile_bar  true = steht in der unteren Leiste auf dem Smartphone
|   scope       nur Einstellungen: 'tenant' (alle Standorte) oder 'location'
|   keywords    nur Einstellungen: Begriffe für die Suche
|
*/

return [
    'mobile_bar_max' => 4,

    'main' => [
        ['label' => null, 'items' => [
            ['key' => 'dashboard', 'label' => 'Übersicht', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
        ]],
        ['label' => 'Service', 'items' => [
            ['key' => 'board', 'label' => 'Live-Board', 'route' => 'admin.board', 'icon' => 'board', 'can' => ['reservations.view'], 'mobile_bar' => true],
            ['key' => 'floorplan', 'label' => 'Tischplan', 'route' => 'admin.floorplan.index', 'icon' => 'floorplan', 'can' => ['reservations.view'], 'only' => 'restaurant'],
            ['key' => 'staff-calendar', 'label' => 'Terminplan', 'route' => 'admin.staff.calendar', 'icon' => 'calendar', 'can' => ['reservations.view'], 'only' => 'salon'],
            ['key' => 'walkins', 'label' => 'Walk-ins', 'route' => 'admin.walkins.index', 'icon' => 'walkins', 'can' => ['walkins.create']],
            ['key' => 'waitlist', 'label' => 'Warteliste', 'route' => 'admin.waitlist.index', 'icon' => 'waitlist', 'can' => ['waitlist.manage']],
        ]],
        ['label' => 'Buchungen', 'items' => [
            ['key' => 'reservations', 'label' => 'Reservierungen', 'label_salon' => 'Termine', 'route' => 'admin.reservations.index', 'icon' => 'reservations', 'can' => ['reservations.view'], 'mobile_bar' => true, 'active' => ['admin.reservations.*']],
            ['key' => 'events', 'label' => 'Events', 'route' => 'admin.events.index', 'icon' => 'events', 'can' => ['events.manage'], 'active' => ['admin.events.*']],
            ['key' => 'refunds', 'label' => 'Erstattungen', 'route' => 'admin.refunds.index', 'icon' => 'refunds', 'can' => ['payments.manage']],
        ]],
        ['label' => 'Gäste', 'items' => [
            ['key' => 'guests', 'label' => 'Gäste', 'label_salon' => 'Kunden', 'route' => 'admin.guests.index', 'icon' => 'guests', 'can' => ['guests.view'], 'mobile_bar' => true, 'active' => ['admin.guests.*']],
            ['key' => 'marketing', 'label' => 'Marketing', 'route' => 'admin.marketing.index', 'icon' => 'marketing', 'can' => ['marketing.manage']],
        ]],
        ['label' => null, 'items' => [
            ['key' => 'reports', 'label' => 'Berichte', 'route' => 'admin.reports.index', 'icon' => 'reports', 'can' => ['reports.view']],
        ]],
    ],

    'settings' => [
        ['label' => 'Betrieb', 'items' => [
            ['key' => 'general', 'label' => 'Allgemein', 'route' => 'admin.settings.index', 'fragment' => 'allgemein', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Betriebsname', 'Betriebstyp', 'Restaurant', 'Salon', 'Markenfarbe', 'Impressum', 'Datenschutz', 'AGB']],
            ['key' => 'locations', 'label' => 'Standorte', 'route' => 'admin.locations.index', 'scope' => 'tenant', 'can' => ['locations.manage'],
                'keywords' => ['Standort', 'Adresse', 'Zeitzone', 'Telefon', 'Logo']],
            ['key' => 'team', 'label' => 'Team & Rollen', 'route' => 'admin.users.index', 'scope' => 'tenant', 'can' => ['users.invite'],
                'keywords' => ['Benutzer', 'Einladen', 'Rollen', 'Zugang', 'Servicekraft']],
            ['key' => 'billing', 'label' => 'Abo & Rechnungen', 'route' => 'admin.billing.show', 'scope' => 'tenant', 'can' => ['billing.manage'], 'active' => ['admin.billing.*'],
                'keywords' => ['Tarif', 'Abo', 'Lastschrift', 'Rechnung']],
            ['key' => 'audit', 'label' => 'Protokoll', 'route' => 'admin.audit.index', 'scope' => 'tenant', 'can' => ['audit.view'],
                'keywords' => ['Auditlog', 'Änderungen', 'Wer hat', 'Verlauf']],
            ['key' => 'data', 'label' => 'Daten', 'route' => 'admin.account.show', 'fragment' => 'daten', 'active' => [], 'scope' => 'tenant', 'can' => ['tenant.settings.manage'],
                'keywords' => ['Export', 'Import', 'Sicherung', 'Betrieb löschen']],
        ]],
        ['label' => 'Platz & Zeiten', 'items' => [
            ['key' => 'opening-hours', 'label' => 'Öffnungszeiten', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Öffnungszeiten', 'Ruhetag', 'Saison', 'Zeitfenster']],
            ['key' => 'exceptions', 'label' => 'Ausnahmen', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Sonderzeiten', 'Feiertag', 'Schließtag', 'Sperrzeit', 'Betriebsferien', 'Tisch sperren', 'Weniger Gäste']],
            ['key' => 'rooms', 'label' => 'Räume & Tische', 'route' => 'admin.settings.index', 'fragment' => 'raeume', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'restaurant',
                'keywords' => ['Raum', 'Tisch', 'Tischkombination', 'Tischplan bearbeiten']],
            ['key' => 'services', 'label' => 'Leistungen', 'route' => 'admin.services.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon', 'active' => ['admin.services.*'],
                'keywords' => ['Leistung', 'Dauer', 'Preis']],
            ['key' => 'staff', 'label' => 'Mitarbeiter', 'route' => 'admin.staff.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon',
                'keywords' => ['Mitarbeiter', 'Arbeitszeiten', 'Abwesenheit', 'Urlaub']],
            ['key' => 'capacity', 'label' => 'Kapazität & Dauer', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Kapazität', 'Besuchsdauer', 'Puffer', 'Slot-Intervall', 'Lückenoptimierung']],
        ]],
        ['label' => 'Buchungen', 'items' => [
            ['key' => 'booking-rules', 'label' => 'Buchungsregeln', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Vorlauf', 'Vorausbuchung', 'Personen online', 'Große Gruppen', 'Stornofrist', 'Umbuchungsfrist', 'Bestätigung', 'Warteliste', 'Walk-ins']],
            ['key' => 'booking-form', 'label' => 'Buchungsformular', 'route' => 'admin.settings.index', 'fragment' => 'allgemein', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Formularfelder', 'Pflichtfeld', 'Telefon', 'Anlass', 'Allergien']],
            ['key' => 'booking-page', 'label' => 'Buchungsseite & Widget', 'route' => 'admin.settings.index', 'fragment' => 'widget', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Website-Widget', 'Einbinden', 'Direktlink', 'Popup', 'Konfetti', 'Öffentlicher Tischplan']],
            ['key' => 'tags', 'label' => 'Tags', 'route' => 'admin.settings.index', 'fragment' => 'raeume', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage'],
                'keywords' => ['Tags', 'Markierung', 'VIP', 'Allergie']],
        ]],
        ['label' => 'Gästekommunikation', 'items' => [
            ['key' => 'notifications', 'label' => 'Benachrichtigungen', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Erinnerung', 'Bestätigungsmail', 'Feedback', 'Benachrichtigung an den Betrieb', 'Anrede', 'du', 'Sie']],
            ['key' => 'templates', 'label' => 'Vorlagen', 'route' => 'admin.templates.index', 'scope' => 'tenant', 'can' => ['templates.manage'],
                'keywords' => ['Vorlagen', 'Mailtext', 'Nachrichtentext']],
            ['key' => 'sender', 'label' => 'Absender & Antworten', 'route' => 'admin.settings.index', 'fragment' => 'allgemein', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Absendername', 'Antwortadresse', 'Antwort über Swayy']],
            ['key' => 'sms', 'label' => 'SMS-Versand', 'route' => 'admin.settings.index', 'fragment' => 'integrationen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['SMS', 'seven.io', 'Absenderkennung']],
        ]],
        ['label' => 'Zahlungen', 'items' => [
            ['key' => 'payment-providers', 'label' => 'Zahlungsanbieter', 'route' => 'admin.settings.index', 'fragment' => 'zahlungen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Stripe', 'PayPal']],
            ['key' => 'deposits', 'label' => 'Anzahlungen', 'route' => 'admin.settings.index', 'fragment' => 'zahlungen', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage', 'payments.manage'],
                'keywords' => ['Anzahlung', 'No-Show-Schutz', 'Rückerstattung', 'Erstattungsregeln']],
        ]],
        ['label' => 'Verbindungen', 'items' => [
            ['key' => 'newsletter', 'label' => 'Newsletter', 'route' => 'admin.settings.index', 'fragment' => 'integrationen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Newsletter', 'MailWizz']],
            ['key' => 'api', 'label' => 'API-Schlüssel', 'route' => 'admin.api-tokens.index', 'scope' => 'tenant', 'can' => ['api_tokens.manage'],
                'keywords' => ['API', 'Schlüssel', 'Token']],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'route' => 'admin.webhooks.index', 'scope' => 'tenant', 'can' => ['webhooks.manage'],
                'keywords' => ['Webhook', 'Ereignisse']],
        ]],
    ],
];
```

- [ ] **Step 6: Test laufen lassen, er ist grün**

Run: `PAO_DISABLE=1 php artisan test --filter=NavigationTest`
Expected: PASS (8 Tests)

- [ ] **Step 7: Pint, PHPStan, Commit**

```bash
vendor/bin/pint app/Support/Navigation config/navigation.php tests/Feature/NavigationTest.php
vendor/bin/phpstan analyse --memory-limit=1G --no-progress app/Support/Navigation
git add app/Support/Navigation config/navigation.php tests/Feature/NavigationTest.php
git commit -m "feat: Navigation der Verwaltung aus config/navigation.php

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Symbole und Einstellungs-Menü

**Files:**
- Create: `resources/views/components/nav-icon.blade.php`
- Create: `app/Http/Controllers/Admin/Settings/SettingsMenuController.php`
- Create: `resources/views/admin/settings/menu.blade.php`
- Modify: `routes/web.php` (Route `admin.settings.menu`)
- Test: `tests/Feature/SettingsMenuTest.php`

**Interfaces:**
- Consumes: `Navigation::fromConfig()->settings(...)` (Task 3)
- Produces: `<x-nav-icon name="…" />` mit den Namen `dashboard, board, reservations, calendar, floorplan, walkins, waitlist, guests, events, refunds, reports, marketing, settings, more, back, search, collapse, chevron, user`; Route `admin.settings.menu` (GET `/admin/settings/menu`); Daten-Attribute der Suche `data-settings-search`, `data-settings-group`, `data-settings-item`, `data-label`, `data-keywords` (mit `|` getrennt), `data-settings-hit`, `data-settings-noresult`.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/SettingsMenuTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsMenuTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_all_settings_groups(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSeeInOrder(['Betrieb', 'Allgemein', 'Platz &amp; Zeiten', 'Öffnungszeiten', 'Verbindungen', 'Webhooks'], false)
            ->assertSee('data-keywords="Tarif|Abo|Lastschrift|Rechnung"', false)
            ->assertSee('data-settings-search', false);
    }

    public function test_salon_sees_services_and_staff_instead_of_rooms(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSee('data-nav-key="services"', false)
            ->assertSee('data-nav-key="staff"', false)
            ->assertDontSee('data-nav-key="rooms"', false);
    }

    public function test_without_any_settings_page_the_menu_explains_the_missing_permission(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/settings/menu')
            ->assertForbidden()
            ->assertSee('Für die Einstellungen fehlt die Berechtigung.');
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=SettingsMenuTest`
Expected: FAIL – 404, Route fehlt.

- [ ] **Step 3: Symbol-Baustein anlegen**

`resources/views/components/nav-icon.blade.php` (Pfade 1:1 aus der bisherigen Seitenleiste; `more`, `back`, `search`, `collapse`, `chevron`, `user` neu, Heroicons Outline):
```blade
@props(['name'])
@php
    $paths = [
        'dashboard' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
        'board' => 'M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.808-3.808-9.98 0-13.789m13.788 0c3.808 3.808 3.808 9.981 0 13.79M12 12h.008v.007H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
        'reservations' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75M3 11.25h18M8.25 15h.008v.008H8.25V15zm3.75 0h.008v.008H12V15zm3.75 0h.008v.008h-.008V15z',
        'floorplan' => 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z',
        'walkins' => 'M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l-3-3m0 0l3-3m-3 3H15',
        'waitlist' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'guests' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'events' => 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
        'refunds' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        'reports' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'marketing' => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253 1.084.575 2.14.959 3.162.535 1.425 2.313 1.865 3.55.884l.088-.07m-4.597-3.976A48.4 48.4 0 0112 15.75c2.305 0 4.547.26 6.7.75m-8.36-.66V6.66m8.36 9.84a24.3 24.3 0 000-9.84m0 9.84c.39.12.78.25 1.17.4 1.07.41 2.1-.4 2.1-1.55V7.83c0-1.15-1.03-1.96-2.1-1.55-.39.15-.78.28-1.17.4',
        'more' => 'M6.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM12.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM18.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'back' => 'M15.75 19.5L8.25 12l7.5-7.5',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        'collapse' => 'M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5',
        'chevron' => 'M8.25 4.5l7.5 7.5-7.5 7.5',
        'user' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    ];
    $multi = [
        'settings' => [
            'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z',
            'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        ],
    ];
    $list = $multi[$name] ?? (isset($paths[$name]) ? [$paths[$name]] : []);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => 'h-4 w-4 shrink-0']) }} fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
    @foreach($list as $d)
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}"/>
    @endforeach
</svg>
```

- [ ] **Step 4: Controller und Route**

`app/Http/Controllers/Admin/Settings/SettingsMenuController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation\Navigation;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Liste aller erlaubten Einstellungsseiten. Auf dem Smartphone der Einstieg
 * in den Einstellungsbereich; auf breiten Bildschirmen führt „Einstellungen“
 * direkt zur ersten erlaubten Seite.
 */
class SettingsMenuController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $groups = Navigation::fromConfig()->settings($request->user(), $tenant, $this->context->location(), null);
        abort_if($groups === [], 403, __('Für die Einstellungen fehlt die Berechtigung. Bitte einen Administrator des Betriebs fragen.'));

        return view('admin.settings.menu', ['groups' => $groups]);
    }
}
```

In `routes/web.php` oben bei den `use`-Anweisungen ergänzen:
```php
use App\Http\Controllers\Admin\Settings\SettingsMenuController;
```
und innerhalb der Admin-Gruppe direkt **vor** `Route::middleware('permission:tables.manage')->group(function () {` (die Gruppe mit `Route::get('/settings', …)`) einfügen:
```php
    // Einstieg in die Einstellungen (Smartphone); prüft selbst, ob eine Seite erlaubt ist.
    Route::get('/settings/menu', SettingsMenuController::class)->name('settings.menu');
```

- [ ] **Step 5: Ansicht anlegen**

`resources/views/admin/settings/menu.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Einstellungen')

@section('content')
<h1 class="mb-4 text-2xl font-bold tracking-tight">Einstellungen</h1>

<div class="relative mb-6 max-w-xl">
    <x-nav-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
    <label for="settingsSearchPage" class="sr-only">Einstellungen durchsuchen</label>
    <input id="settingsSearchPage" type="search" data-settings-search aria-controls="settingsSearchPage-list"
           autocomplete="off" placeholder="Einstellungen durchsuchen" class="w-full pl-9">
</div>

<div id="settingsSearchPage-list" class="max-w-xl space-y-6">
    @foreach($groups as $group)
        <section data-settings-group>
            @if($group->label)
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-stone-500">{{ $group->label }}</h2>
            @endif
            <div class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                @foreach($group->items as $item)
                    <a href="{{ $item->url }}" data-settings-item data-nav-key="{{ $item->key }}"
                       data-label="{{ $item->label }}" data-keywords="{{ implode('|', $item->keywords) }}"
                       class="flex min-h-12 items-center justify-between gap-3 px-4 py-3 hover:bg-stone-50 focus-visible:bg-stone-50">
                        <span>
                            <span class="block font-medium">{{ $item->label }}</span>
                            <small data-settings-hit hidden class="block text-xs text-teal-800"></small>
                        </span>
                        <x-nav-icon name="chevron" class="h-4 w-4 text-stone-400" />
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
    <p data-settings-noresult hidden class="text-sm text-stone-600"></p>
</div>
@endsection
```

- [ ] **Step 6: Test laufen lassen, er ist grün**

Run: `PAO_DISABLE=1 php artisan test --filter='SettingsMenuTest|AdminRoutesSmokeTest'`
Expected: PASS. Der Smoke-Test ruft die neue Route mit auf.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app/Http/Controllers/Admin/Settings routes/web.php tests/Feature/SettingsMenuTest.php
git add resources/views/components/nav-icon.blade.php app/Http/Controllers/Admin/Settings resources/views/admin/settings/menu.blade.php routes/web.php tests/Feature/SettingsMenuTest.php
git commit -m "feat: Einstellungs-Menü und Symbole der Navigation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Layout mit neuer Navigation

**Files:**
- Modify: `resources/views/layouts/admin.blade.php` (Seitenleiste, Kopfleiste, untere Leiste, „Mehr“, Hinweisfläche, Stapel `drawers`; altes Smartphone-Menü und `swayyToggleMenu` entfallen)
- Create: `resources/views/admin/partials/nav/main.blade.php`
- Create: `resources/views/admin/partials/nav/settings.blade.php`
- Create: `resources/views/admin/partials/nav/user.blade.php`
- Create: `resources/views/admin/partials/nav/location-switch.blade.php`
- Create: `resources/js/admin/nav.js`
- Create: `resources/js/admin/index.js`
- Modify: `resources/js/app.js` (Import und Start)
- Modify: `resources/css/app.css` (Symbolleiste, Fehlermarkierung)
- Test: `tests/Feature/NavigationLayoutTest.php`

**Interfaces:**
- Consumes: `Navigation` (Task 3), `<x-nav-icon>`, Route `admin.settings.menu` (Task 4), `window.SwayyAdmin` (Task 2)
- Produces:
  - `<body data-admin>`; `#main-content`; `#swayyToast` (`role="status"`, Klassen `opacity-0 pointer-events-none` im Ruhezustand, `data-state="ok|error"`); `@stack('drawers')` außerhalb von `#main-content`
  - Seitenleiste `aside[data-nav-rail][data-nav-mode="main|settings"]` mit Knopf `[data-nav-collapse]`; Klassen `.nav-label`, `.nav-heading`, `.nav-full`, `.nav-rail-only`
  - untere Leiste `nav[aria-label="Schnellzugriff"]` mit `[data-more-open]`; Blatt `#swayyMore` mit `[data-more-close]`
  - `select[data-autosubmit]` schickt sein Formular beim Ändern ab
  - `resources/js/admin/index.js` exportiert `initAdmin()`; `nav.js` exportiert `initRail()`, `initMoreSheet()`, `initAutosubmit()`, `initSettingsSearch()`
  - localStorage-Schlüssel `swayy.nav.collapsed` (`'1'`/`'0'`)

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/NavigationLayoutTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NavigationLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_daily_pages_show_the_grouped_main_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/reservations')->assertOk();

        $response->assertSee('aria-label="Hauptnavigation"', false)
            ->assertSeeInOrder(['data-nav-key="dashboard"', 'Service', 'data-nav-key="board"', 'Buchungen', 'data-nav-key="reservations"'], false)
            ->assertSee('data-nav-key="settings"', false)
            ->assertSee('aria-label="Schnellzugriff"', false)
            ->assertSee('data-more-open', false)
            ->assertDontSee('data-nav-key="webhooks"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="reservations"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_settings_pages_swap_the_sidebar_for_the_settings_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/users')->assertOk();

        $response->assertSee('aria-label="Einstellungen"', false)
            ->assertSee('Zurück zum Betrieb')
            ->assertSee('Einstellungen durchsuchen')
            ->assertSee('data-nav-key="webhooks"', false)
            ->assertDontSee('aria-label="Hauptnavigation"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="team"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_roles_without_settings_get_no_settings_entry(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/reservations')
            ->assertOk()
            ->assertDontSee('data-nav-key="settings"', false);
    }

    public function test_salon_labels_reach_the_layout(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('Termine')
            ->assertSee('data-nav-key="staff-calendar"', false);
    }

    public function test_layout_and_navigation_use_no_inline_event_handlers(): void
    {
        $files = [
            resource_path('views/layouts/admin.blade.php'),
            resource_path('views/admin/partials/nav/main.blade.php'),
            resource_path('views/admin/partials/nav/settings.blade.php'),
            resource_path('views/admin/partials/nav/user.blade.php'),
            resource_path('views/admin/partials/nav/location-switch.blade.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|keydown)\s*=/i', (string) file_get_contents($file), $file);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=NavigationLayoutTest`
Expected: FAIL – u. a. `aria-label="Hauptnavigation"` fehlt, Partials existieren nicht.

- [ ] **Step 3: Partials anlegen**

`resources/views/admin/partials/nav/main.blade.php`:
```blade
{{-- Hauptnavigation: Gruppen aus App\Support\Navigation, darunter „Einstellungen“. --}}
@foreach($groups as $group)
    <div class="mt-4 first:mt-0">
        @if($group->label)
            <p class="nav-heading px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-stone-400">{{ $group->label }}</p>
        @endif
        <div class="space-y-0.5">
            @foreach($group->items as $item)
                <a href="{{ $item->url }}" data-nav-key="{{ $item->key }}" title="{{ $item->label }}"
                   @if($item->active) aria-current="page" @endif
                   class="flex min-h-9 items-center gap-2.5 rounded-md px-3 py-2 {{ $item->active ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
                    <x-nav-icon :name="$item->icon" /><span class="nav-label">{{ $item->label }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endforeach
@if($settingsUrl)
    <div class="mt-4 border-t border-stone-700 pt-4">
        <a href="{{ $settingsUrl }}" data-nav-key="settings" title="Einstellungen"
           @if($settingsActive) aria-current="page" @endif
           class="flex min-h-9 items-center gap-2.5 rounded-md px-3 py-2 {{ $settingsActive ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
            <x-nav-icon name="settings" /><span class="nav-label">Einstellungen</span>
        </a>
    </div>
@endif
```

`resources/views/admin/partials/nav/settings.blade.php`:
```blade
{{-- Einstellungsnavigation: Zurück, Suche, Gruppen (Suche: resources/js/admin/nav.js). --}}
<a href="{{ route('admin.dashboard') }}"
   class="mb-3 flex min-h-9 items-center gap-2 rounded-md px-2 text-sm text-stone-300 hover:bg-stone-800 hover:text-white">
    <x-nav-icon name="back" /> Zurück zum Betrieb
</a>
<div class="relative mb-2">
    <x-nav-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
    <label for="{{ $searchId }}" class="sr-only">Einstellungen durchsuchen</label>
    <input id="{{ $searchId }}" type="search" data-settings-search aria-controls="{{ $searchId }}-list"
           autocomplete="off" placeholder="Einstellungen durchsuchen"
           class="h-9 w-full rounded-md border border-stone-700 bg-stone-800 pl-8 pr-2 text-sm text-white placeholder:text-stone-400">
</div>
<div id="{{ $searchId }}-list">
    @foreach($groups as $group)
        <div data-settings-group class="mt-4 first:mt-2">
            @if($group->label)
                <p class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-stone-400">{{ $group->label }}</p>
            @endif
            @foreach($group->items as $item)
                <a href="{{ $item->url }}" data-settings-item data-nav-key="{{ $item->key }}"
                   data-label="{{ $item->label }}" data-keywords="{{ implode('|', $item->keywords) }}"
                   @if($item->active) aria-current="page" @endif
                   class="block rounded-md px-2 py-1.5 {{ $item->active ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
                    {{ $item->label }}
                    <small data-settings-hit hidden class="block text-xs font-normal text-teal-300"></small>
                </a>
            @endforeach
        </div>
    @endforeach
    <p data-settings-noresult hidden class="mt-4 px-2 text-sm text-stone-400"></p>
</div>
```

`resources/views/admin/partials/nav/location-switch.blade.php`:
```blade
{{-- Standortwahl: bei mehreren Standorten eine Auswahl, sonst der Name. --}}
@if($tenant && $tenant->locations->count() > 1)
    <form method="POST" action="{{ route('admin.switch-location') }}">
        @csrf
        <label for="{{ $switchId }}" class="sr-only">Standort wählen</label>
        <select id="{{ $switchId }}" name="location_id" data-autosubmit
                class="w-full rounded-md border-0 bg-stone-800 px-2 py-1.5 text-sm text-stone-100">
            @foreach($tenant->locations as $loc)
                <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
            @endforeach
        </select>
    </form>
@elseif($location)
    <div class="text-sm text-stone-200">{{ $location->name }}</div>
@endif
```

`resources/views/admin/partials/nav/user.blade.php`:
```blade
{{-- Nutzerbereich unten in der Seitenleiste und im Blatt „Mehr“. --}}
<div class="border-t border-stone-700 p-4 text-sm">
    <a href="{{ route('admin.account.show') }}" title="Mein Konto"
       class="nav-rail-only h-9 w-9 items-center justify-center rounded-md text-stone-300 hover:bg-stone-800 hover:text-white">
        <x-nav-icon name="user" /><span class="sr-only">Mein Konto</span>
    </a>
    <div class="nav-full">
        <div class="mb-2 truncate text-stone-300">{{ $user->name }}</div>
        @if(session('impersonating_tenant_id'))
            <form method="POST" action="{{ route('saas.stop-impersonation') }}" class="mb-2">
                @csrf
                <button class="w-full rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold">Supportzugriff beenden</button>
            </form>
        @endif
        <div class="flex flex-col gap-1">
            <a href="{{ route('admin.account.show') }}"
               class="block rounded-md px-3 py-1.5 text-center text-xs text-stone-300 hover:bg-stone-700 hover:text-white">Mein Konto</a>
            @if($user->isSaasAdmin())
                <a href="{{ route('saas.dashboard') }}"
                   class="block rounded-md px-3 py-1.5 text-center text-xs text-stone-300 hover:bg-stone-700 hover:text-white">Plattform-Admin</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full rounded-md bg-stone-700 px-3 py-1.5 text-xs hover:bg-stone-600">Abmelden</button>
            </form>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Layout neu fassen**

`resources/views/layouts/admin.blade.php` vollständig ersetzen. Unverändert übernommen werden der `head`-Inhalt aus Task 2, Lizenzhinweis, Meldungen, Fußzeile und der Bestätigungsdialog samt Skript (`#confirmModal`).
```blade
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Swayy') – Swayy</title>
    <script>
        window.SwayyAdmin = @json([
            'ui' => [
                'toastMs' => config('swayy.ui.toast_ms'),
            ],
            'problemTexts' => \App\Support\AdminJsonErrors::forClient(),
        ]);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body data-admin class="min-h-screen bg-stone-100 text-stone-900 antialiased">
<a href="#main-content" class="skip-link">Zum Inhalt springen</a>
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
    $user = auth()->user();
    $routeName = request()->route()?->getName();
    $nav = \App\Support\Navigation\Navigation::fromConfig();
    $settingsMode = $nav->isSettingsRoute($routeName);
    $mainGroups = $tenant ? $nav->main($user, $tenant, $location, $routeName) : [];
    $settingsGroups = $tenant ? $nav->settings($user, $tenant, $location, $routeName) : [];
    $mobileBar = $tenant ? $nav->mobileBar($user, $tenant, $location, $routeName) : [];
    $settingsUrl = $tenant ? $nav->firstSettingsUrl($user, $tenant, $location) : null;
@endphp

<div class="flex min-h-screen">
    {{-- Seitenleiste: ab 1280 px voll, 768–1279 px einklappbar, darunter ausgeblendet --}}
    <aside id="swayyNav" data-nav-rail data-nav-mode="{{ $settingsMode ? 'settings' : 'main' }}"
           class="nav-rail sticky top-0 hidden h-screen shrink-0 flex-col bg-stone-900 text-stone-100 md:flex">
        <div class="flex items-center justify-between gap-2 px-4 py-4">
            <span class="nav-full px-1 text-lg font-bold tracking-tight">Swayy<x-beta /></span>
            <button type="button" data-nav-collapse aria-controls="swayyNav" aria-expanded="true" aria-label="Navigation einklappen"
                    class="nav-collapse flex h-9 w-9 items-center justify-center rounded-md text-stone-300 hover:bg-stone-800 hover:text-white">
                <x-nav-icon name="collapse" />
            </button>
        </div>
        @if($tenant)
            <div class="nav-full px-5 pb-3 text-xs text-stone-400">{{ $tenant->name }}</div>
            <div class="nav-full px-4 pb-4">
                @include('admin.partials.nav.location-switch', ['switchId' => 'locationSwitchDesktop'])
            </div>
        @endif
        <nav aria-label="{{ $settingsMode ? 'Einstellungen' : 'Hauptnavigation' }}" class="flex-1 overflow-y-auto px-3 pb-4 text-sm">
            @if($settingsMode)
                @include('admin.partials.nav.settings', ['groups' => $settingsGroups, 'searchId' => 'settingsSearchDesktop'])
            @else
                @include('admin.partials.nav.main', ['groups' => $mainGroups, 'settingsUrl' => $settingsUrl, 'settingsActive' => false])
            @endif
        </nav>
        @include('admin.partials.nav.user')
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Kopfleiste auf dem Smartphone --}}
        <header class="sticky top-0 z-30 flex min-h-12 items-center gap-2 bg-stone-900 px-3 text-stone-100 md:hidden">
            @if($settingsMode && $settingsUrl)
                <a href="{{ route('admin.settings.menu') }}" class="flex min-h-10 items-center gap-1.5 rounded-md px-2 text-sm hover:bg-stone-800">
                    <x-nav-icon name="back" /> Einstellungen
                </a>
            @else
                <span class="px-2 font-bold">Swayy<x-beta /></span>
            @endif
            @if($location)
                <span class="ml-auto truncate px-2 text-xs text-stone-400">{{ $location->name }}</span>
            @endif
        </header>

        <main id="main-content" class="flex-1 p-4 pb-24 md:p-6">
            @include('admin.partials.license_banner')
            @if(session('success'))
                <div data-flash role="status" class="mb-4 flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <span class="mt-px font-bold">✓</span><span>{{ session('success') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div data-flash="sticky" role="alert" class="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <div>
                        <p class="mb-1 font-semibold">Bitte prüfen Sie Ihre Eingaben:</p>
                        <ul class="list-disc space-y-0.5 pl-5">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif
            @yield('content')

            <footer class="mt-10 text-center text-[11px] text-stone-400">
                Swayy v{{ config('version.number') }} &middot; <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600">Beta</span>
            </footer>
        </main>
    </div>
</div>

@if($tenant)
    {{-- Untere Leiste auf dem Smartphone: die häufigsten Ziele und „Mehr“ --}}
    <nav aria-label="Schnellzugriff"
         class="fixed inset-x-0 bottom-0 z-40 grid border-t border-stone-800 bg-stone-900 text-stone-300 md:hidden"
         style="grid-template-columns: repeat({{ count($mobileBar) + 1 }}, minmax(0, 1fr))">
        @foreach($mobileBar as $item)
            <a href="{{ $item->url }}" data-nav-key="{{ $item->key }}" @if($item->active) aria-current="page" @endif
               class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] {{ $item->active ? 'text-white' : 'hover:text-white' }}">
                <x-nav-icon :name="$item->icon" class="h-5 w-5" /><span class="truncate">{{ $item->label }}</span>
            </a>
        @endforeach
        <button type="button" data-more-open aria-controls="swayyMore" aria-expanded="false"
                class="flex min-h-14 flex-col items-center justify-center gap-1 text-[11px] hover:text-white">
            <x-nav-icon name="more" class="h-5 w-5" />Mehr
        </button>
    </nav>

    {{-- „Mehr“: volle Navigation auf dem Smartphone --}}
    <div id="swayyMore" hidden class="fixed inset-0 z-50 md:hidden">
        <div class="absolute inset-0 bg-black/50" data-more-close></div>
        <div role="dialog" aria-modal="true" aria-label="Navigation"
             class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-stone-900 p-4 text-stone-100 shadow-2xl">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-bold">{{ $tenant->name }}</span>
                <button type="button" data-more-close aria-label="Schließen"
                        class="flex h-10 w-10 items-center justify-center rounded-md text-2xl leading-none hover:bg-stone-800">×</button>
            </div>
            <div class="pb-3">
                @include('admin.partials.nav.location-switch', ['switchId' => 'locationSwitchMobile'])
            </div>
            <nav aria-label="Alle Bereiche" class="text-sm">
                @include('admin.partials.nav.main', [
                    'groups' => $mainGroups,
                    'settingsUrl' => $settingsUrl ? route('admin.settings.menu') : null,
                    'settingsActive' => $settingsMode,
                ])
            </nav>
            @include('admin.partials.nav.user')
        </div>
    </div>
@endif

@stack('drawers')

{{-- Hinweise („Gespeichert“, Fehler); resources/js/admin/toast.js --}}
<div id="swayyToast" role="status" aria-live="polite" data-state="ok"
     class="pointer-events-none fixed bottom-20 left-1/2 z-[60] flex max-w-[90vw] -translate-x-1/2 items-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white opacity-0 shadow-xl transition-opacity data-[state=error]:bg-red-700 data-[state=ok]:bg-stone-900 md:bottom-6"></div>

{{-- Global confirm dialog for forms/links with data-confirm (touch-friendly) --}}
<div id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[380px] max-w-[96vw] rounded-2xl bg-white p-6 text-center shadow-2xl">
        <h3 id="confirmModalTitle" class="mb-1 text-lg font-bold">Bestätigen</h3>
        <p id="confirmModalMsg" class="mb-5 text-sm text-stone-500"></p>
        <div class="flex gap-3">
            <button type="button" id="confirmModalCancel"
                    class="h-13 flex-1 rounded-xl border border-stone-200 bg-stone-50 py-3.5 text-base font-bold text-stone-700">Abbrechen</button>
            <button type="button" id="confirmModalOk"
                    class="h-13 flex-1 rounded-xl bg-stone-900 py-3.5 text-base font-bold text-white">OK</button>
        </div>
    </div>
</div>
<script>
// Any element with data-confirm intercepts its form submit (or click) and asks
// first via a touch-friendly modal. Optional: data-confirm-ok, data-confirm-style="danger".
(function () {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmModalTitle');
    const msgEl = document.getElementById('confirmModalMsg');
    const okBtn = document.getElementById('confirmModalOk');
    const cancelBtn = document.getElementById('confirmModalCancel');
    let pending = null;
    let lastFocused = null;

    function openModal() {
        lastFocused = document.activeElement;
        modal.classList.remove('hidden'); modal.classList.add('flex');
        okBtn.focus();
    }
    function closeModal() {
        modal.classList.add('hidden'); modal.classList.remove('flex'); pending = null;
        if (lastFocused && lastFocused.focus) lastFocused.focus();
    }

    okBtn.addEventListener('click', () => { const fn = pending; closeModal(); if (fn) fn(); });
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

    document.addEventListener('submit', e => {
        const el = e.target.querySelector('[data-confirm]') || (e.submitter && e.submitter.matches('[data-confirm]') ? e.submitter : null);
        const trigger = e.target.matches('[data-confirm]') ? e.target : el;
        if (!trigger) return;
        e.preventDefault();
        titleEl.textContent = trigger.dataset.confirmTitle || 'Bestätigen';
        msgEl.textContent = trigger.dataset.confirm;
        okBtn.textContent = trigger.dataset.confirmOk || 'OK';
        okBtn.className = 'h-13 flex-1 rounded-xl py-3.5 text-base font-bold text-white '
            + (trigger.dataset.confirmStyle === 'danger' ? 'bg-red-600' : 'bg-emerald-600');
        pending = () => e.target.submit();
        openModal();
    });
})();
</script>

@stack('scripts')
</body>
</html>
```

Der Bestätigungsdialog ist wörtlich aus der bisherigen Datei übernommen. Das alte `#swayyMobileMenu` samt `swayyToggleMenu`-Skript entfällt.

- [ ] **Step 5: Navigationsskript**

`resources/js/admin/nav.js`:
```js
// Navigation der Verwaltung: Symbolleiste am Tablet, „Mehr“ auf dem
// Smartphone, Standortwahl ohne Inline-Handler, Suche im Einstellungsbereich.

const RAIL_KEY = 'swayy.nav.collapsed';

// Ohne gespeicherten Wert startet die Symbolleiste eingeklappt: Am Tablet
// bleibt so Platz für Live-Board und Tischplan.
function readCollapsed() {
    try {
        const value = window.localStorage.getItem(RAIL_KEY);
        return value === null ? true : value === '1';
    } catch {
        return true;
    }
}

function writeCollapsed(value) {
    try {
        window.localStorage.setItem(RAIL_KEY, value ? '1' : '0');
    } catch {
        // Gesperrter Speicher (privates Fenster): Der Zustand gilt bis zum Neuladen.
    }
}

export function initRail() {
    const rail = document.querySelector('[data-nav-rail]');
    const toggle = document.querySelector('[data-nav-collapse]');
    if (!rail || !toggle) return;

    const apply = (collapsed) => {
        rail.dataset.collapsed = collapsed ? 'true' : 'false';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Navigation ausklappen' : 'Navigation einklappen');
    };

    apply(readCollapsed());
    toggle.addEventListener('click', () => {
        const next = rail.dataset.collapsed !== 'true';
        apply(next);
        writeCollapsed(next);
    });
}

export function initMoreSheet() {
    const sheet = document.getElementById('swayyMore');
    const opener = document.querySelector('[data-more-open]');
    if (!sheet || !opener) return;
    const main = document.getElementById('main-content');

    const open = () => {
        sheet.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        main?.setAttribute('inert', '');
        sheet.querySelector('[role="dialog"] a, [role="dialog"] button, [role="dialog"] select')?.focus();
    };
    const close = () => {
        sheet.hidden = true;
        opener.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        main?.removeAttribute('inert');
        opener.focus();
    };

    opener.addEventListener('click', open);
    sheet.querySelectorAll('[data-more-close]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !sheet.hidden) close();
    });
}

export function initAutosubmit() {
    document.addEventListener('change', (e) => {
        const el = e.target;
        if (el instanceof HTMLSelectElement && el.hasAttribute('data-autosubmit') && el.form) {
            el.form.requestSubmit();
        }
    });
}

export function initSettingsSearch() {
    document.querySelectorAll('[data-settings-search]').forEach((input) => {
        const list = document.getElementById(input.getAttribute('aria-controls'));
        if (!list) return;
        const noResult = list.querySelector('[data-settings-noresult]');

        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('de');
            let any = false;

            list.querySelectorAll('[data-settings-group]').forEach((group) => {
                let visible = 0;
                group.querySelectorAll('[data-settings-item]').forEach((link) => {
                    const label = (link.dataset.label || '').toLocaleLowerCase('de');
                    const words = (link.dataset.keywords || '').split('|').filter(Boolean);
                    let hit = '';
                    let match = query === '' || label.includes(query);
                    if (!match) {
                        hit = words.find((word) => word.toLocaleLowerCase('de').includes(query)) || '';
                        match = hit !== '';
                    }
                    link.hidden = !match;
                    const hint = link.querySelector('[data-settings-hit]');
                    if (hint) {
                        hint.textContent = hit;
                        hint.hidden = hit === '';
                    }
                    if (match) visible += 1;
                });
                group.hidden = visible === 0;
                if (visible > 0) any = true;
            });

            if (noResult) {
                noResult.hidden = any;
                noResult.textContent = any
                    ? ''
                    : `Zu „${input.value.trim()}“ passt keine Einstellung. Versuch einen anderen Begriff, etwa „Erinnerung“ oder „Stornofrist“.`;
            }
        });
    });
}
```

`resources/js/admin/index.js`:
```js
// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
}
```

In `resources/js/app.js` als erste Zeile einfügen:
```js
import { initAdmin } from './admin/index.js';
```
und am Ende der Datei anhängen:
```js
// ---------------------------------------------------------------------------
// Verwaltung (layouts/admin.blade.php setzt data-admin): Navigation,
// Formulare, Speicherleiste.
// ---------------------------------------------------------------------------
if (document.body?.hasAttribute('data-admin')) {
    initAdmin();
}
```

- [ ] **Step 6: CSS für Symbolleiste und Fehlermarkierung**

Am Ende von `resources/css/app.css` anhängen:
```css
/* Navigation der Verwaltung (layouts/admin.blade.php, resources/js/admin/nav.js). */
.nav-rail {
    width: 15rem;
    transition: width 0.15s ease;
}
.nav-rail-only {
    display: none;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] {
        width: 4.25rem;
    }
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] .nav-label,
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] .nav-heading {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] .nav-full {
        display: none;
    }
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] .nav-rail-only {
        display: flex;
    }
    .nav-rail[data-collapsed='true'][data-nav-mode='main'] [data-nav-key] {
        justify-content: center;
    }
}
@media (min-width: 1280px) {
    .nav-collapse {
        display: none;
    }
}
.nav-rail[data-nav-mode='settings'] .nav-collapse {
    display: none;
}
@media (prefers-reduced-motion: reduce) {
    .nav-rail {
        transition: none;
    }
}

/* Felder, die der Server als fehlerhaft meldet (resources/js/admin/forms.js). */
[aria-invalid='true'] {
    border-color: #b91c1c;
    box-shadow: 0 0 0 3px rgba(185, 28, 28, 0.15);
}
```

- [ ] **Step 7: Verweise auf das alte Smartphone-Menü suchen**

Run: `grep -rn "swayyToggleMenu\|swayyMobileMenu\|swayyMenuBtn" resources app tests`
Expected: keine Treffer. Gibt es Treffer, verweisen sie auf das alte Menü und werden auf `[data-more-open]` umgestellt.

- [ ] **Step 8: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='NavigationLayoutTest|AdminRoutesSmokeTest|BetaMarkTest|LicenseTest|OnboardingBannerTest|PermissionsTest'`
Expected: PASS

Run: `PAO_DISABLE=1 php artisan test`
Expected: PASS, Anzahl = Ausgangslage + neue Tests.

- [ ] **Step 9: Im Browser prüfen**

Build und Vorschau:
```bash
npm run build
```
Danach `preview_start` mit `gastrobook-new-ui`, anmelden als `owner@demo.test`. Prüfen:
1. 1280 px: Seitenleiste mit „Service“, „Buchungen“, „Gäste“; „Übersicht“ und „Berichte“ ohne Überschrift; „Einstellungen“ unten.
2. 900 px (`resize_window` 900 × 800): eingeklappt mit Symbolen; Knopf klappt aus; nach Neuladen bleibt der Zustand.
3. 390 px (`resize_window` Preset `mobile`): untere Leiste mit vier Zielen und „Mehr“; „Mehr“ öffnet das Blatt, Escape schließt, der Fokus kehrt zurück.
4. `/admin/users`: Einstellungsnavigation mit „Zurück zum Betrieb“; Suche „Stornofrist“ zeigt „Buchungsregeln“ mit dem Begriff darunter; „xyz“ zeigt den Hinweis zur Suche.
5. Konsole ohne Fehler (`read_console_messages` mit `onlyErrors`).

- [ ] **Step 10: Commit**

```bash
git add resources/views/layouts/admin.blade.php resources/views/admin/partials/nav resources/js resources/css/app.css tests/Feature/NavigationLayoutTest.php
git commit -m "feat: Layout mit gruppierter Navigation, Symbolleiste und unterer Leiste

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Seitenbausteine

**Files:**
- Create: `resources/views/components/page-header.blade.php`
- Create: `resources/views/components/scope-switch.blade.php`
- Create: `resources/views/components/settings/section.blade.php`
- Create: `resources/views/components/field.blade.php`
- Create: `resources/views/components/save-bar.blade.php`
- Test: `tests/Feature/PageComponentsTest.php`

**Interfaces:**
- Consumes: `TenantContext`, Route `admin.switch-location`, `[data-autosubmit]` (Task 5)
- Produces:
  - `<x-page-header title description :crumb="[…]" scope="tenant|location">` mit Slot `actions`
  - `<x-scope-switch scope="tenant|location" />`
  - `<x-settings.section id title>` mit Slot `description` und Standard-Slot (Karteninhalt)
  - `<x-field name label type value help unit required id>`; ID-Regel `f-` + Name mit `[^A-Za-z0-9_]+` → `-` (`hours[0][opens_at]` → `f-hours-0-opens_at`); Hilfetext-ID `{id}-help`, Fehler-ID `{id}-error`; Standard-Slot ersetzt das Eingabefeld
  - `<x-save-bar />` mit `[data-save-bar]`, `[data-save-bar-text]`, `[data-save-bar-discard]`, `[data-save-bar-save]`

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/PageComponentsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PageComponentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_field_puts_the_label_above_and_links_help_text(): void
    {
        $this->blade('<x-field name="buffer_minutes" label="Puffer" type="number" value="15" unit="Min." help="Zeit zwischen zwei Buchungen am selben Tisch." min="0" max="120" />')
            ->assertSeeInOrder(['<label for="f-buffer_minutes"', 'Puffer', 'id="f-buffer_minutes"', 'Min.', 'Zeit zwischen zwei Buchungen'], false)
            ->assertSee('aria-describedby="f-buffer_minutes-help"', false)
            ->assertSee('min="0"', false)
            ->assertSee('value="15"', false);
    }

    public function test_field_shows_the_server_error_at_the_field(): void
    {
        $this->withViewErrors(['buffer_minutes' => 'Bitte eine Zahl von 0 bis 120 eintragen.'])
            ->blade('<x-field name="buffer_minutes" label="Puffer" type="number" />')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="f-buffer_minutes-error"', false)
            ->assertSee('Bitte eine Zahl von 0 bis 120 eintragen.');
    }

    public function test_field_ids_stay_valid_for_array_names(): void
    {
        $this->blade('<x-field name="hours[0][opens_at]" label="Beginn" type="time" />')
            ->assertSee('id="f-hours-0-opens_at"', false)
            ->assertSee('name="hours[0][opens_at]"', false);
    }

    public function test_page_header_names_the_scope_for_all_locations(): void
    {
        $this->blade('<x-page-header title="Allgemein" description="Gilt für den Betrieb." :crumb="[\'Einstellungen\', \'Betrieb\']" scope="tenant" />')
            ->assertSeeInOrder(['Einstellungen › Betrieb', 'Allgemein', 'Gilt für den Betrieb.', 'Gilt für alle Standorte'], false);
    }

    public function test_page_header_offers_the_location_switch_with_several_locations(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Zweiter Standort']);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $this->blade('<x-page-header title="Öffnungszeiten" scope="location" />')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-autosubmit', false)
            ->assertSee('Zweiter Standort');
    }

    public function test_settings_section_pairs_explanation_and_card(): void
    {
        $this->blade('<x-settings.section id="betrieb" title="Betrieb"><x-slot:description>Erklärung links.</x-slot:description>Karteninhalt</x-settings.section>')
            ->assertSee('aria-labelledby="betrieb-title"', false)
            ->assertSeeInOrder(['id="betrieb-title"', 'Betrieb', 'Erklärung links.', 'Karteninhalt'], false);
    }

    public function test_save_bar_starts_hidden(): void
    {
        $this->blade('<x-save-bar />')
            ->assertSee('data-save-bar hidden', false)
            ->assertSee('Ungespeicherte Änderungen')
            ->assertSee('data-save-bar-discard', false)
            ->assertSee('data-save-bar-save', false);
    }
}
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter=PageComponentsTest`
Expected: FAIL – `Unable to locate a class or view for component [field]`.

- [ ] **Step 3: Bausteine anlegen**

`resources/views/components/page-header.blade.php`:
```blade
@props(['title', 'description' => null, 'crumb' => [], 'scope' => null])
{{-- Seitenkopf: Pfad, Titel, ein Satz, Geltungsbereich, eine Hauptaktion (Slot "actions"). --}}
<div class="mb-6 border-b border-stone-200 pb-5">
    @if($crumb)
        <nav aria-label="Pfad" class="mb-1 text-[13px] text-stone-500">{{ implode(' › ', $crumb) }}</nav>
    @endif
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
            @if($description)
                <p class="mt-1.5 max-w-2xl text-sm text-stone-600">{{ $description }}</p>
            @endif
        </div>
        @if($scope || isset($actions))
            <div class="flex flex-wrap items-center gap-2.5">
                @if($scope)
                    <x-scope-switch :scope="$scope" />
                @endif
                {{ $actions ?? '' }}
            </div>
        @endif
    </div>
</div>
```

`resources/views/components/scope-switch.blade.php`:
```blade
@props(['scope'])
{{-- Geltungsbereich einer Einstellungsseite; bei mehreren Standorten mit Wechsel. --}}
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
@endphp
@if($scope === 'tenant')
    <span class="text-[13px] text-stone-600">Gilt für alle Standorte</span>
@elseif($tenant && $tenant->locations->count() > 1)
    <form method="POST" action="{{ route('admin.switch-location') }}" class="flex items-center gap-2 text-[13px] text-stone-600">
        @csrf
        <label for="scopeLocation">Gilt für Standort</label>
        <select id="scopeLocation" name="location_id" data-autosubmit class="h-9 py-0 text-sm font-semibold text-stone-900">
            @foreach($tenant->locations as $loc)
                <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
            @endforeach
        </select>
    </form>
@elseif($location)
    <span class="text-[13px] text-stone-600">Gilt für Standort <strong class="font-semibold text-stone-900">{{ $location->name }}</strong></span>
@endif
```

`resources/views/components/settings/section.blade.php`:
```blade
@props(['id', 'title'])
{{-- Einstellung als Karte mit Erklärung: ab 1024 px links Titel und Erklärung, rechts die Karte. --}}
<section id="{{ $id }}" aria-labelledby="{{ $id }}-title"
         class="grid gap-4 border-t border-stone-200 py-8 first-of-type:border-t-0 first-of-type:pt-2 lg:grid-cols-3 lg:gap-8">
    <div>
        <h2 id="{{ $id }}-title" class="text-base font-semibold">{{ $title }}</h2>
        @isset($description)
            <div class="mt-1.5 space-y-2 text-sm text-stone-600">{{ $description }}</div>
        @endisset
    </div>
    <div class="lg:col-span-2">
        <div class="rounded-xl border border-stone-200 bg-white shadow-[0_1px_2px_rgba(28,25,23,0.04)]">
            <div class="space-y-5 px-5 py-5 sm:px-6">{{ $slot }}</div>
        </div>
    </div>
</section>
```

`resources/views/components/field.blade.php`:
```blade
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'unit' => null,
    'required' => false,
    'id' => null,
])
{{-- Feld mit Beschriftung darüber, Hilfesatz und Fehler am Feld. Der Standard-Slot ersetzt das Eingabefeld (Auswahl, Textfeld). --}}
@php
    $id = $id ?? 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $dotted = str_replace(['[', ']'], ['.', ''], $name);
    // $errors teilt die Middleware ShareErrorsFromSession; ausserhalb einer
    // Anfrage (Komponententest, Mail) fehlt die Variable.
    $bag = isset($errors) ? $errors : new \Illuminate\Support\ViewErrorBag();
    $error = $bag->first($dotted);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $id }}" class="block text-sm font-semibold text-stone-800">
        {{ $label }}@if($required)<span class="text-red-700" aria-hidden="true"> *</span>@endif
    </label>
    @if($slot->isNotEmpty())
        {{ $slot }}
    @else
        <div class="flex items-stretch">
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($dotted, $value) }}"
                   @if($required) required aria-required="true" @endif
                   @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                   @if($error) aria-invalid="true" @endif
                   {{ $attributes->except(['class'])->merge(['class' => 'w-full min-w-0'.($unit ? ' rounded-r-none' : '')]) }}>
            @if($unit)
                <span class="inline-flex items-center rounded-r border border-l-0 border-stone-300 bg-stone-50 px-3 text-sm text-stone-600">{{ $unit }}</span>
            @endif
        </div>
    @endif
    @if($help)
        <p id="{{ $id }}-help" class="text-[13px] text-stone-500">{{ $help }}</p>
    @endif
    @if($error)
        <p id="{{ $id }}-error" class="text-[13px] text-red-700">{{ $error }}</p>
    @endif
</div>
```

`resources/views/components/save-bar.blade.php`:
```blade
{{-- Speicherleiste: erscheint bei Änderungen in Formularen mit data-savebar (resources/js/admin/savebar.js).
     Am Smartphone über der unteren Leiste, ab 768 px oben im Inhalt und beim Scrollen stehend. --}}
<div data-save-bar hidden role="region" aria-label="Ungespeicherte Änderungen"
     class="fixed inset-x-0 bottom-14 z-40 flex flex-wrap items-center justify-between gap-3 bg-stone-900 px-4 py-3 text-white shadow-[0_-6px_16px_rgba(28,25,23,0.18)] md:sticky md:top-0 md:bottom-auto md:-mx-6 md:-mt-6 md:mb-6 md:px-6">
    <p class="flex items-center gap-2.5 text-sm font-semibold">
        <span class="h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
        <span data-save-bar-text>Ungespeicherte Änderungen</span>
    </p>
    <div class="flex w-full gap-2 sm:w-auto">
        <button type="button" data-save-bar-discard
                class="min-h-9 flex-1 rounded-md border border-stone-600 px-3 text-sm font-semibold hover:bg-stone-800 sm:flex-none">Verwerfen</button>
        <button type="button" data-save-bar-save
                class="min-h-9 flex-1 rounded-md bg-white px-3 text-sm font-semibold text-stone-900 hover:bg-stone-100 sm:flex-none">Speichern</button>
    </div>
</div>
```

- [ ] **Step 4: Test laufen lassen, er ist grün**

Run: `PAO_DISABLE=1 php artisan test --filter=PageComponentsTest`
Expected: PASS (7 Tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint tests/Feature/PageComponentsTest.php
git add resources/views/components tests/Feature/PageComponentsTest.php
git commit -m "feat: Seitenbausteine Seitenkopf, Karte mit Erklärung, Feld, Speicherleiste

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Formulare, Hinweise und Speicherleiste im Browser

**Files:**
- Create: `resources/js/admin/toast.js`
- Create: `resources/js/admin/forms.js`
- Create: `resources/js/admin/savebar.js`
- Modify: `resources/js/admin/index.js`

**Interfaces:**
- Consumes: `#swayyToast`, `window.SwayyAdmin.ui.toastMs`, `window.SwayyAdmin.problemTexts` (Schlüssel `401 … 504`, `default`, `network`, `unclear`), `[data-save-bar…]` (Task 6), JSON-Antworten der Controller `{ message, reload? }` bzw. `{ message, errors }` mit Status 422
- Produces:
  - `showToast(message: string, { error?: boolean, undo?: () => void }): void`
  - `submitForm(form: HTMLFormElement): Promise<boolean>`; bei Erfolg Event `swayy:saved` (`detail` = JSON) am Formular, Vorgabewerte der Felder werden auf den gespeicherten Stand gesetzt
  - Formulare mit `data-ajax` senden per fetch; Formulare mit `data-savebar` steuern die Speicherleiste; Event `swayy:discarded` nach „Verwerfen“, `swayy:rebase` setzt den Vergleichsstand neu
  - Fehlerbox `[data-form-problems]` (`role="alert"`) oben im Formular, Fokus auf das erste gemeldete Feld

- [ ] **Step 1: Hinweise**

`resources/js/admin/toast.js`:
```js
// Kurzer Hinweis unten („Gespeichert“), per Live-Region angesagt.
// Anzeigedauer aus config/swayy.php (ui.toast_ms) über window.SwayyAdmin.

let timer;

export function showToast(message, { error = false, undo = null } = {}) {
    const el = document.getElementById('swayyToast');
    if (!el) return;

    el.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message;
    el.append(text);

    if (typeof undo === 'function') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ml-3 rounded px-2 py-1 font-semibold underline underline-offset-2 hover:bg-white/10';
        button.textContent = 'Rückgängig';
        button.addEventListener('click', () => {
            hide(el);
            undo();
        });
        el.append(button);
    }

    el.dataset.state = error ? 'error' : 'ok';
    el.classList.remove('opacity-0', 'pointer-events-none');
    clearTimeout(timer);
    timer = setTimeout(() => hide(el), window.SwayyAdmin?.ui?.toastMs ?? 3200);
}

function hide(el) {
    el.classList.add('opacity-0', 'pointer-events-none');
}
```

- [ ] **Step 2: Formulare**

`resources/js/admin/forms.js`:
```js
// Formulare mit data-ajax senden per fetch und melden Ergebnis und Fehler an
// Ort und Stelle. Texte für Antworten ohne JSON (Proxy-Fehlerseite,
// Weiterleitung, abgebrochene Verbindung) kommen aus
// App\Support\AdminJsonErrors::forClient() über window.SwayyAdmin.problemTexts.

import { showToast } from './toast.js';

let problemCount = 0;

function texts() {
    return window.SwayyAdmin?.problemTexts ?? {};
}

async function readJson(res) {
    if (!(res.headers.get('Content-Type') || '').includes('json')) return null;
    try {
        return await res.json();
    } catch {
        return null;
    }
}

function problemsOf(res, json) {
    if (json?.errors) return Object.values(json.errors).flat();
    if (json?.message) return [json.message];
    const t = texts();
    const text = t[res.status] || (res.status >= 500 ? t[500] : t.default) || '';
    return [text.replace(':status', String(res.status))];
}

function clearProblems(form) {
    form.querySelector('[data-form-problems]')?.remove();
    form.querySelectorAll('[data-ajax-invalid]').forEach((el) => {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('data-ajax-invalid');
        const ids = (el.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && !id.startsWith('formProblems'));
        if (ids.length) {
            el.setAttribute('aria-describedby', ids.join(' '));
        } else {
            el.removeAttribute('aria-describedby');
        }
    });
}

// Zusammenfassung oben im Formular, gemeldete Felder mit aria-invalid und
// Verweis auf die Zusammenfassung ("hours.0.opens_at" heißt im Formular
// "hours[0][opens_at]"). Der Fokus springt zum ersten gemeldeten Feld.
function showProblems(form, json, messages) {
    const box = document.createElement('div');
    box.id = `formProblems${++problemCount}`;
    box.dataset.formProblems = '';
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.className = 'rounded-lg border border-red-200 border-l-4 border-l-red-700 bg-red-50 px-3 py-2 text-sm text-red-800';

    if (messages.length === 1) {
        box.textContent = messages[0];
    } else {
        const list = document.createElement('ul');
        list.className = 'list-disc space-y-1 pl-4';
        messages.forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });
        box.append(list);
    }

    let first = null;
    Object.keys(json?.errors ?? {}).forEach((key) => {
        const [head, ...rest] = key.split('.');
        const name = head + rest.map((part) => `[${part}]`).join('');
        form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`).forEach((el) => {
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('data-ajax-invalid', '');
            el.setAttribute('aria-describedby', `${el.getAttribute('aria-describedby') || ''} ${box.id}`.trim());
            first ??= el;
        });
    });

    form.prepend(box);
    (first ?? box).focus();
}

// Nach dem Speichern gilt der gespeicherte Stand als Vorgabe: „Verwerfen“
// (form.reset) kehrt dann zu ihm zurück.
function commitDefaults(form) {
    for (const el of form.elements) {
        if (el instanceof HTMLInputElement) {
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.defaultChecked = el.checked;
            } else if (el.type !== 'file') {
                el.defaultValue = el.value;
            }
        } else if (el instanceof HTMLTextAreaElement) {
            el.defaultValue = el.value;
        } else if (el instanceof HTMLSelectElement) {
            for (const option of el.options) option.defaultSelected = option.selected;
        }
    }
}

export async function submitForm(form) {
    clearProblems(form);
    const submitter = form.querySelector('[type="submit"]');
    submitter?.setAttribute('aria-busy', 'true');

    try {
        const multipart = form.enctype === 'multipart/form-data';
        const body = multipart ? new FormData(form) : new URLSearchParams(new FormData(form));
        const headers = { Accept: 'application/json' };
        if (!multipart) headers['Content-Type'] = 'application/x-www-form-urlencoded';

        const res = await fetch(form.action || window.location.href, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            headers,
            body,
            credentials: 'same-origin',
        });
        const json = await readJson(res);

        if (!res.ok) {
            const messages = problemsOf(res, json);
            showToast(messages.join(' · '), { error: true });
            showProblems(form, json, messages);
            return false;
        }

        // Gespeichert meldet nur, wer es bestätigt. Eine Antwort ohne JSON
        // (etwa nach einer Weiterleitung) lässt offen, ob die Änderung ankam.
        if (!json || typeof json.message !== 'string') {
            const message = texts().unclear || '';
            showToast(message, { error: true });
            showProblems(form, null, [message]);
            return false;
        }

        commitDefaults(form);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json }));
        showToast(json.message);
        if (json.reload) setTimeout(() => window.location.reload(), 700);
        return true;
    } catch {
        const message = texts().network || '';
        showToast(message, { error: true });
        showProblems(form, null, [message]);
        return false;
    } finally {
        submitter?.removeAttribute('aria-busy');
    }
}

export function initAjaxForms() {
    document.querySelectorAll('form[data-ajax]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm(form);
        });
    });
}
```

- [ ] **Step 3: Speicherleiste**

`resources/js/admin/savebar.js`:
```js
// Speicherleiste (x-save-bar): erscheint, sobald ein Formular mit
// data-savebar geändert ist. „Speichern“ schickt die geänderten Formulare
// nacheinander ab, „Verwerfen“ setzt sie auf den gespeicherten Stand zurück.
// Wer die Seite mit Änderungen verlässt, bekommt die Rückfrage des Browsers.

import { submitForm } from './forms.js';
import { showToast } from './toast.js';

const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();

export function initSaveBar() {
    const bar = document.querySelector('[data-save-bar]');
    const forms = [...document.querySelectorAll('form[data-savebar]')];
    if (!bar || forms.length === 0) return;

    const base = new Map(forms.map((form) => [form, snapshot(form)]));
    const dirtyForms = () => forms.filter((form) => snapshot(form) !== base.get(form));

    const render = () => {
        const dirty = dirtyForms();
        bar.hidden = dirty.length === 0;
        forms.forEach((form) => form.toggleAttribute('data-dirty', dirty.includes(form)));
    };

    forms.forEach((form) => {
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        const rebase = () => {
            base.set(form, snapshot(form));
            render();
        };
        form.addEventListener('swayy:saved', rebase);
        form.addEventListener('swayy:rebase', rebase);
    });

    bar.querySelector('[data-save-bar-discard]')?.addEventListener('click', () => {
        dirtyForms().forEach((form) => {
            form.reset();
            form.dispatchEvent(new CustomEvent('swayy:discarded'));
        });
        render();
        showToast('Änderungen verworfen');
    });

    bar.querySelector('[data-save-bar-save]')?.addEventListener('click', async () => {
        for (const form of dirtyForms()) {
            const ok = await submitForm(form);
            if (!ok) break;
        }
        render();
    });

    window.addEventListener('beforeunload', (e) => {
        if (dirtyForms().length > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}
```

- [ ] **Step 4: Start erweitern**

`resources/js/admin/index.js` ersetzen:
```js
// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initAjaxForms } from './forms.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initSaveBar } from './savebar.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initSaveBar();
}
```

- [ ] **Step 5: Build prüfen**

Run: `npm run build`
Expected: Build ohne Fehler. Die Prüfung im Browser folgt in Task 8 an der ersten Seite, die diese Skripte nutzt.

- [ ] **Step 6: Commit**

```bash
git add resources/js/admin
git commit -m "feat: Formulare per fetch, Hinweise und Speicherleiste in der Verwaltung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Seite „Allgemein“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/Concerns/RespondsToSettingsForms.php`
- Create: `app/Http/Controllers/Admin/Settings/GeneralController.php`
- Create: `resources/views/admin/settings/general.blade.php`
- Modify: `app/Http/Controllers/Admin/SettingsController.php` (Trait nutzen; `updateGeneral`, `updateBranding`, `updateTenantType` entfernen)
- Modify: `routes/web.php` (drei Routen auf `GeneralController`, neue GET-Route)
- Modify: `app/Models/AuditLog.php` (Bezeichnung `tenant.general_updated`)
- Modify: `config/navigation.php` (Eintrag `general` auf die neue Seite)
- Modify: `tests/Feature/NavigationTest.php` (erste Einstellungsseite des Inhabers)
- Test: `tests/Feature/SettingsGeneralPageTest.php`

**Interfaces:**
- Consumes: Bausteine aus Task 6, Skripte aus Task 7, `AuditLogger::log()`, `TenantType::cases()`, `TenantType::label()`, `config('swayy.ui.default_brand_color')`
- Produces:
  - Trait `App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms` mit `protected function saved(Request $request, string $message, bool $reload = false): mixed`, `protected function failed(Request $request, string $field, string $message): mixed`, `protected function rejected(Request $request, array $errors): mixed`
  - `GeneralController@show` (GET `/admin/settings/general`, Name `admin.settings.general.show`), `@update` (PUT `/admin/settings/general`, Name `admin.settings.general`), `@updateBranding` (PUT `/admin/settings/branding`), `@updateTenantType` (PUT `/admin/settings/tenant-type`)
  - `update` akzeptiert die Standortfelder weiter, verlangt sie aber nicht mehr (die alte Sammelseite schickt sie bis Teil 2 mit)
  - Audit-Aktion `tenant.general_updated`

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/SettingsGeneralPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsGeneralPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_the_general_page_in_the_settings_area(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['name' => 'Beispiel Gastro GmbH']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/general')->assertOk();

        $response->assertSee('Einstellungen › Betrieb')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('value="Beispiel Gastro GmbH"', false)
            ->assertSee('data-save-bar', false)
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('Zurück zum Betrieb');
        $this->assertMatchesRegularExpression('/data-nav-key="general"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_the_brand_color_falls_back_to_the_configured_default(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => null]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertSee('value="#123456"', false);
    }

    public function test_roles_without_tenant_settings_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/general')->assertForbidden();
    }

    public function test_saving_business_data_leaves_the_location_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $locationName = $setup['location']->name;
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', [
                'business_name' => 'Nordlicht Events',
                'imprint_url' => 'https://nordlicht.test/impressum',
                'privacy_url' => 'https://nordlicht.test/datenschutz',
                'terms_url' => '',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Stammdaten gespeichert.']);

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Nordlicht Events', $tenant->name);
        $this->assertSame('https://nordlicht.test/impressum', $tenant->imprint_url);
        $this->assertNull($tenant->terms_url);
        $this->assertSame($locationName, $setup['location']->fresh()->name);
        $this->assertTrue(AuditLog::where('action', 'tenant.general_updated')->exists());
    }

    public function test_an_invalid_link_names_the_field_and_the_fix(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', ['business_name' => 'Nordlicht Events', 'imprint_url' => 'impressum'])
            ->assertStatus(422)
            ->assertJsonPath('errors.imprint_url.0', 'Die Adresse des Impressums ist ungültig. Bitte vollständig eintragen, etwa https://dein-betrieb.de/impressum.');
    }
}
```

In `tests/Feature/NavigationTest.php` in `test_settings_are_grouped_and_filtered_by_permission` die letzte Zusicherung ersetzen durch:
```php
        $this->assertSame(route('admin.settings.general.show'), $nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
```

- [ ] **Step 2: Test laufen lassen, er schlägt fehl**

Run: `PAO_DISABLE=1 php artisan test --filter='SettingsGeneralPageTest|NavigationTest'`
Expected: FAIL – Route `admin.settings.general.show` fehlt.

- [ ] **Step 3: Antwort-Helfer als Trait**

`app/Http/Controllers/Admin/Settings/Concerns/RespondsToSettingsForms.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings\Concerns;

use Illuminate\Http\Request;

/**
 * Antworten der Einstellungsformulare: per fetch als JSON („message“, bei
 * Ablehnung zusätzlich „errors“ mit Status 422), sonst zurück zur Seite.
 */
trait RespondsToSettingsForms
{
    protected function saved(Request $request, string $message, bool $reload = false): mixed
    {
        if ($request->wantsJson()) {
            $data = ['message' => $message];
            if ($reload) {
                $data['reload'] = true;
            }

            return response()->json($data);
        }

        return back()->with('success', $message);
    }

    protected function failed(Request $request, string $field, string $message): mixed
    {
        return $this->rejected($request, [$field => $message]);
    }

    /**
     * Ablehnung in derselben Form wie ein Prüfungsfehler von Laravel.
     *
     * @param  array<string, string>  $errors  Feld => Meldung
     */
    protected function rejected(Request $request, array $errors): mixed
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => implode(' ', $errors),
                'errors' => array_map(fn (string $message) => [$message], $errors),
            ], 422);
        }

        return back()->withErrors($errors);
    }
}
```

In `app/Http/Controllers/Admin/SettingsController.php`:
1. Import ergänzen: `use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;`
2. In der Klasse als erste Zeile: `use RespondsToSettingsForms;`
3. Die privaten Methoden `saved`, `failed` und `rejected` am Ende der Klasse löschen.
4. Die Methoden `updateGeneral`, `updateBranding` und `updateTenantType` löschen (sie ziehen in den `GeneralController`). Danach nicht mehr benötigte Imports entfernen (`TenantType`, falls nur dort genutzt).

- [ ] **Step 4: `GeneralController`**

`app/Http/Controllers/Admin/Settings/GeneralController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Betrieb › Allgemein: Name, Rechtslinks, Betriebstyp,
 * Markenfarbe. Gilt für alle Standorte.
 */
class GeneralController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        return view('admin.settings.general', [
            'tenant' => $tenant,
            'brandColor' => $tenant->brand_primary_color ?: config('swayy.ui.default_brand_color'),
        ]);
    }

    /**
     * Stammdaten des Betriebs. Die alte Sammelseite schickt bis zu ihrem
     * Abbau auch die Standortdaten mit; die Seite „Allgemein“ schickt sie
     * nicht, dann bleibt der Standort unverändert.
     */
    public function update(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $tenant = $location->tenant;

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            // Die Buchungsseite verlangt vom Gast ein Pflichthäkchen auf die
            // Datenschutzhinweise; diese Links führen dorthin.
            'imprint_url' => ['nullable', 'url', 'max:255'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'terms_url' => ['nullable', 'url', 'max:255'],
            'location_name' => ['sometimes', 'required', 'string', 'max:120'],
            'timezone' => ['required_with:location_name', 'timezone'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'public_intro' => ['nullable', 'string', 'max:1000'],
        ], [
            'business_name.required' => __('Bitte den Namen des Betriebs eintragen.'),
            'imprint_url.url' => __('Die Adresse des Impressums ist ungültig. Bitte vollständig eintragen, etwa https://dein-betrieb.de/impressum.'),
            'privacy_url.url' => __('Die Adresse der Datenschutzhinweise ist ungültig. Bitte vollständig eintragen, etwa https://dein-betrieb.de/datenschutz.'),
            'terms_url.url' => __('Die Adresse der AGB ist ungültig. Bitte vollständig eintragen, etwa https://dein-betrieb.de/agb.'),
        ]);

        $tenantValues = [
            'name' => $validated['business_name'],
            'imprint_url' => $validated['imprint_url'] ?? null,
            'privacy_url' => $validated['privacy_url'] ?? null,
            'terms_url' => $validated['terms_url'] ?? null,
        ];
        $old = $tenant->only(array_keys($tenantValues));
        $tenant->update($tenantValues);

        if (! $request->has('location_name')) {
            $this->audit->log('tenant.general_updated', $tenant, $old, $tenantValues);

            return $this->saved($request, __('Stammdaten gespeichert.'));
        }

        // Slug stays fixed on rename so existing booking links keep working.
        $location->update([
            'name' => $validated['location_name'],
            'timezone' => $validated['timezone'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
            'public_intro' => $validated['public_intro'] ?? null,
        ]);

        $this->audit->log('location.general_updated', $location, null, $validated);

        return $this->saved($request, __('Stammdaten gespeichert.'), reload: true);
    }

    public function updateBranding(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'brand_primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'brand_primary_color.regex' => __('Die Farbe muss im Format #rrggbb angegeben sein, etwa #0f766e. Bitte über das Farbfeld auswählen.'),
        ]);

        $old = ['brand_primary_color' => $tenant->brand_primary_color];
        $tenant->update($validated);

        $this->audit->log('tenant.branding_updated', $tenant, $old, $validated);

        return $this->saved($request, __('Markenfarbe gespeichert.'));
    }

    public function updateTenantType(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:restaurant,salon'],
        ], [], ['type' => __('Betriebstyp')]);

        $old = $tenant->getRawOriginal('type');
        $tenant->update(['type' => TenantType::from($validated['type'])]);

        $this->audit->log('tenant.type_changed', $tenant, ['type' => $old], ['type' => $validated['type']]);

        // reload: Der Typwechsel ändert Navigation, Bezeichnungen und die
        // öffentliche Buchungsseite.
        return $this->saved($request, __('Betriebstyp geändert auf: :type', [
            'type' => TenantType::from($validated['type'])->label(),
        ]), reload: true);
    }
}
```

- [ ] **Step 5: Routen umstellen**

In `routes/web.php` den Import ergänzen:
```php
use App\Http\Controllers\Admin\Settings\GeneralController;
```
In der Gruppe `Route::middleware('permission:tables.manage')->group(...)` die drei Routen ersetzen:
```php
        Route::get('/settings/general', [GeneralController::class, 'show'])
            ->middleware('permission:tenant.settings.manage')->name('settings.general.show');
        Route::put('/settings/general', [GeneralController::class, 'update'])
            ->middleware('permission:tenant.settings.manage')->name('settings.general');
        Route::put('/settings/tenant-type', [GeneralController::class, 'updateTenantType'])
            ->middleware('permission:tenant.settings.manage')->name('settings.tenant-type');
        Route::put('/settings/branding', [GeneralController::class, 'updateBranding'])
            ->middleware('permission:tenant.settings.manage')->name('settings.branding');
```

- [ ] **Step 6: Audit-Bezeichnung**

In `app/Models/AuditLog.php` neben `'location.general_updated' => 'Standort: Stammdaten geändert',` ergänzen:
```php
        'tenant.general_updated' => 'Betrieb: Stammdaten geändert',
```

- [ ] **Step 7: Navigation auf die neue Seite**

In `config/navigation.php` den Eintrag `general` ersetzen:
```php
            ['key' => 'general', 'label' => 'Allgemein', 'route' => 'admin.settings.general.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Betriebsname', 'Betriebstyp', 'Restaurant', 'Salon', 'Markenfarbe', 'Impressum', 'Datenschutz', 'AGB']],
```

- [ ] **Step 8: Ansicht**

`resources/views/admin/settings/general.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Allgemein')

@section('content')
<x-save-bar />
<x-page-header title="Allgemein" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Name, Art und Auftritt deines Betriebs." />

<x-settings.section id="betrieb" title="Betrieb">
    <x-slot:description>
        <p>Der Name erscheint in der Verwaltung, in Mails an Gäste und auf der Buchungsseite.</p>
        <p>Die drei Links führen Gäste auf der Buchungsseite zu deinen Rechtstexten. Die Datenschutzhinweise bestätigt ein Gast vor dem Buchen.</p>
    </x-slot:description>
    <form id="formGeneral" method="POST" action="{{ route('admin.settings.general') }}" data-ajax data-savebar class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="business_name" label="Name des Betriebs" :value="$tenant->name" required maxlength="120" />
        <x-field name="imprint_url" label="Impressum" type="url" :value="$tenant->imprint_url" placeholder="https://dein-betrieb.de/impressum" />
        <x-field name="privacy_url" label="Datenschutzhinweise" type="url" :value="$tenant->privacy_url" placeholder="https://dein-betrieb.de/datenschutz"
                 help="Gäste bestätigen diese Hinweise vor dem Buchen." />
        <x-field name="terms_url" label="AGB" type="url" :value="$tenant->terms_url" placeholder="https://dein-betrieb.de/agb" />
    </form>
</x-settings.section>

<x-settings.section id="betriebstyp" title="Betriebstyp">
    <x-slot:description>
        <p>Restaurants arbeiten mit Räumen, Tischen und Reservierungen, Salons mit Leistungen, Mitarbeitern und Terminen. Ein Wechsel passt Navigation und Buchungsseite sofort an.</p>
    </x-slot:description>
    <form id="formType" method="POST" action="{{ route('admin.settings.tenant-type') }}" data-ajax data-savebar>
        @csrf
        @method('PUT')
        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="sr-only">Betriebstyp</legend>
            @foreach(\App\Enums\TenantType::cases() as $type)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-teal-700 has-[:checked]:ring-1 has-[:checked]:ring-teal-700">
                    <input type="radio" name="type" value="{{ $type->value }}" @checked($tenant->type === $type) class="mt-1">
                    <span>
                        <span class="block font-semibold">{{ $type->label() }}</span>
                        <span class="block text-[13px] text-stone-500">
                            {{ $type === \App\Enums\TenantType::Salon ? 'Termine je Mitarbeiter und Leistung' : 'Räume, Tische und Reservierungen' }}
                        </span>
                    </span>
                </label>
            @endforeach
        </fieldset>
    </form>
</x-settings.section>

<x-settings.section id="markenfarbe" title="Markenfarbe">
    <x-slot:description>
        <p>Die Farbe erscheint auf der Buchungsseite und auf dem Buchungsknopf für deine Website. Am besten die Hauptfarbe deines Logos.</p>
    </x-slot:description>
    <form id="formBrand" method="POST" action="{{ route('admin.settings.branding') }}" data-ajax data-savebar>
        @csrf
        @method('PUT')
        <x-field name="brand_primary_color" label="Farbe" help="Ein Klick auf das Farbfeld öffnet die Auswahl.">
            <input id="f-brand_primary_color" type="color" name="brand_primary_color" value="{{ $brandColor }}"
                   aria-describedby="f-brand_primary_color-help"
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </form>
</x-settings.section>
@endsection
```

- [ ] **Step 9: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='SettingsGeneralPageTest|NavigationTest|GeneralSettingsTest|BrandingLogoTest|SettingsFormErrorsTest|AuditLabelTest|TenantType'`
Expected: PASS. `GeneralSettingsTest` (Standortfelder über die alte Sammelseite) bleibt grün.

Run: `PAO_DISABLE=1 php artisan test`
Expected: PASS

- [ ] **Step 10: Im Browser prüfen**

`npm run build`, Vorschau `gastrobook-new-ui`, als `owner@demo.test` `/admin/settings/general` öffnen:
1. Seitenkopf mit „Einstellungen › Betrieb“, „Allgemein“, „Gilt für alle Standorte“; links die Einstellungsnavigation mit „Allgemein“ markiert.
2. Namen ändern: Die Speicherleiste erscheint oben; „Verwerfen“ setzt zurück und zeigt „Änderungen verworfen“.
3. Namen ändern, „Speichern“: Hinweis „Stammdaten gespeichert.“, die Leiste verschwindet; nach Neuladen steht der neue Name im Feld.
4. Impressum `impressum` eintragen, „Speichern“: Fehlerbox oben im Formular mit dem Text aus Step 1, Fokus im Feld „Impressum“, Feld rot umrandet.
5. Betriebstyp wechseln, „Speichern“: Die Seite lädt neu, die Navigation zeigt die Salon-Bezeichnungen; danach zurück auf Restaurant.
6. 390 px: Speicherleiste unten über der unteren Leiste, Karten untereinander, kein seitliches Scrollen (`javascript_tool`: `document.documentElement.scrollWidth <= window.innerWidth`).

- [ ] **Step 11: Pint, PHPStan, Commit**

```bash
vendor/bin/pint app/Http/Controllers/Admin routes/web.php app/Models/AuditLog.php config/navigation.php tests/Feature/SettingsGeneralPageTest.php tests/Feature/NavigationTest.php
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
git add app/Http/Controllers/Admin routes/web.php app/Models/AuditLog.php config/navigation.php resources/views/admin/settings/general.blade.php tests/Feature/SettingsGeneralPageTest.php tests/Feature/NavigationTest.php
git commit -m "feat: Einstellungsseite Allgemein im neuen Aufbau

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Prüfung Teil 1

**Files:**
- Modify: nur, wenn die Prüfung etwas findet (jeder Fund als eigener Commit)

**Interfaces:**
- Consumes: alles aus Task 1–8
- Produces: grüne Qualitätstore, geprüfter Stand von Teil 1 auf `new-ui`

- [ ] **Step 1: Qualitätstore**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
PAO_DISABLE=1 php artisan test
npm run build
```
Expected: alle vier ohne Befund; Testanzahl = Ausgangslage aus Task 1 + neue Tests.

- [ ] **Step 2: Breiten durchgehen**

Vorschau `gastrobook-new-ui`, angemeldet als `owner@demo.test`. Für `/admin`, `/admin/reservations`, `/admin/users`, `/admin/settings/general` und `/admin/settings/menu` jeweils bei 1280 × 900, 900 × 800 und 390 × 844 (`resize_window`):
- Screenshot ansehen: Navigation, Seitenkopf, Karten stehen sauber, nichts überlappt.
- `javascript_tool`: `document.documentElement.scrollWidth <= window.innerWidth` ergibt `true`.
- Danach `resize_window` mit Preset `desktop` zurücksetzen.

- [ ] **Step 3: Tastatur und Screenreader-Merkmale**

Bei 1280 px auf `/admin/settings/general`:
1. Tab ab Seitenanfang: zuerst „Zum Inhalt springen“, dann Navigation, dann Inhalt; jeder Fokus sichtbar.
2. Feld ändern, mit Tab zur Speicherleiste, „Speichern“ per Enter.
3. `read_page` mit `filter: interactive`: Navigation trägt `aria-current="page"` am aktuellen Eintrag; Hinweisfläche ist `role="status"`.
4. Als `host@demo.test`: kein Eintrag „Einstellungen“; `/admin/settings/menu` zeigt die Meldung zur fehlenden Berechtigung.

- [ ] **Step 4: Konsole**

`read_console_messages` mit `onlyErrors: true` auf allen geprüften Seiten.
Expected: keine Fehler.

- [ ] **Step 5: Befunde beheben**

Jeder Fund: Ursache im Code beheben, betroffene Tests ergänzen, Qualitätstore aus Step 1 erneut, eigener Commit `fix: …`.

- [ ] **Step 6: Übergabe**

Stand an Mathias melden: was neu funktioniert (Navigation, Einstellungs-Menü, Seite „Allgemein“), Testanzahl, offene Punkte. Erst nach seiner Freigabe:
```bash
git push -u origin new-ui
gh pr create --draft --base main --head new-ui --title "Neue Oberfläche (new-ui)" --body "Entwurf, wächst mit Teil 1–3 der Spec docs/superpowers/specs/2026-10-07-new-ui-admin-design.md.

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```
Danach Auto-fix-Überwachung für den PR einschalten und den Plan für Teil 2 schreiben.

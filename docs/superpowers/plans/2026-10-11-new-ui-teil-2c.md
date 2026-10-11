# Neue Oberfläche, Teil 2c: Gästekommunikation, Zahlungen, Verbindungen – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die letzten neun Einstellungsseiten (Benachrichtigungen, Vorlagen, Absender & Antworten, SMS-Versand, Zahlungsanbieter, Anzahlungen, Newsletter, API-Schlüssel, Webhooks) bekommen das neue Seitenmuster und eigene Speicherwege, dazu eigene Fehlerseiten mit nächstem Schritt, „Passwort ändern“ unter „Mein Konto“ und die Restpunkte des Protokolls.

**Architecture:** Jede Seite bekommt einen Controller unter `App\Http\Controllers\Admin\Settings\*` (oder behält ihren bestehenden Controller mit neuem Layout) und eine Ansicht unter `resources/views/admin/settings/*`. Sie nutzt die Bausteine aus 2a/2b: `x-page-header`, `x-settings.section`, `x-save-bar`, `x-field`, `x-checkbox`, `x-drawer`, `x-inline-confirm`, `x-empty-state`, `x-status-icon` und die Module `forms.js`, `savebar.js`, `drawer.js`, `inline-confirm.js`, `reveal.js`, `minutes.js`, `copy.js`. Antworten laufen über `RespondsToSettingsForms` (`saved`, `failed`, `rejected`). Die bestehenden PUT-Routen der Sammelseite bleiben unter Adresse und Namen und zeigen auf die neuen Controller; die Sammelseite bleibt bis Teil 3 bedienbar.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Tailwind CSS 4 (Vite), Vanilla-JS als ES-Module, Vitest + jsdom, PHPUnit (SQLite und PostgreSQL 17), Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`

## Fahrplan

| Plan | Inhalt |
|---|---|
| 2a, 2b | erledigt (Protokolle `2026-10-08-new-ui-teil-2a-protokoll.md`, `2026-10-10-new-ui-teil-2b-protokoll.md`) |
| **2c (dieser)** | Gästekommunikation, Zahlungen, Verbindungen, Fehlerseiten, Passwort ändern, Restpunkte Protokoll |
| 3 | Öffnungszeiten mit Vorschau, Ausnahmen mit „Sperren…“, Abbau der Sammelseite, Weiterleitungen, Zuordnungstest, Handbuch, Restpunkte; danach Zusammenführen mit `main` und Ausrollen (nach Freigabe) |

Freigabe Mathias (11.10.2026, „schlank ja mit sonnet“): schlanke Aufteilung, je Aufgabe ein Umsetzer und ein Prüfer, ein Gesamtprüfer, alle auf Sonnet 5.5. Aufgabe 0 (Abgleich mit `main` 1.134.8, Merge aabd562) und Aufgabe 7 führt die steuernde Sitzung selbst aus.

## Entscheidungen

| Frage | Entscheidung | Grund |
|---|---|---|
| Rechte der neuen Seiten | GET-Routen liegen in derselben Gruppe wie die Speicherwege (`permission:tables.manage`) und tragen zusätzlich das Recht ihrer Karte. Teil 3 löst die Gruppe `tables.manage` auf, wenn die Sammelseite geht. | Spec: jede Karte behält das Recht ihrer Route; eine Seite, die sich öffnen, aber nicht speichern lässt, wäre schlechter |
| Zahlungsanbieter und andere Seiten mit Geheimnissen | Eigene Formulare je Anbieter mit eigenem Knopf „Speichern“, ohne Speicherleiste. Ein leeres Geheimnisfeld behält den gespeicherten Wert. | Geheimnisfelder sind nach dem Speichern leer; die Speicherleiste würde sie nie als Änderung erkennen |
| Erstattung der Anzahlung | Eigene Karte auf „Anzahlungen“ mit Speicherleiste und eigenem Speicherweg `PUT /admin/settings/refunds` (Recht `opening_hours.manage` wie heute über `settings.booking-rules`) | Spec: „Anzahlungsregeln und Rückerstattung auf einer Seite“ |
| Anzahlungsregeln | Liste mit Seitenleiste zum Anlegen und Bearbeiten, Löschen per Rückfrage in der Zeile. Fremde Räume oder Leistungen → 422 mit Grund (heute still verworfen). Beträge in Euro mit Punkt, Umrechnung in Cent über Ziffern wie bei Leistungen | Konvention aus 2b |
| Vorlagen | Liste mit Status „Angepasst“/„Standard“, Bearbeiten in der Seitenleiste `vorlage-{key}`, Zurücksetzen per Rückfrage. Benachrichtigungen verlinken mit `#vorlage-{key}` | Spec: „Text bearbeiten“ führt zur passenden Vorlage |
| Bestätigungsmail | Karte „Bestätigung“ ohne Schalter: Swayy schickt sie immer; die Karte erklärt das und verlinkt die Vorlagen | Heute gibt es keinen Schalter dafür |
| Anrede | Erste Karte „Anrede“ auf Benachrichtigungen, wirkt auf Web, Mail und SMS (`LocationSettings::du()`) | Spec, Zuordnung |
| Neuer API-Schlüssel, neues Webhook-Geheimnis | Einmalige Anzeige wie heute über die Sitzung nach dem Neuladen, mit Kopierknopf (`copy.js`) | bestehendes Verhalten |
| Fehlerseiten | Eigene Ansichten für 403, 404, 419, 429, 500, 503 unter `resources/views/errors/` mit Ursache und nächstem Schritt; eine `abort(403, 'Text')`-Meldung erscheint als Ursache. Die Verwaltung antwortet bei `fetch` weiter mit JSON (`AdminJsonErrors`) | globale Regel zu Fehlermeldungen |
| Passwort ändern | Karte „Passwort“ in „Mein Konto“: aktuelles Passwort, neues Passwort, Wiederholung; Regeln wie beim Zurücksetzen, Mindestlänge aus `swayy.auth.password_min_length` | Spec: „Mein Konto“ enthält Passwort |
| Grenzen | Erinnerung, Feedback, Anzahlungen, Vorlagen und Passwort bekommen Einstellungen in `config/swayy.php`; die alte Sammelseite liest dieselben Grenzen | Restpunkt aus 2b, Regel „Nichts fest im Code“ |

## Global Constraints

- Farben, Schriften und Tokens von Swayy bleiben (Stein-Palette, Teal-Akzent `--brand`, Inter). Kein Gestaltungsschema von `bright-color-apps`.
- Rechte unverändert: `config/permissions.php` wird nicht geändert; jede bestehende Route behält ihre `permission:`-Middleware. Neue Seiten: Benachrichtigungen `tables.manage` + `opening_hours.manage`; Vorlagen `templates.manage`; Absender & Antworten `tables.manage` + `tenant.settings.manage`; SMS-Versand, Zahlungsanbieter, Newsletter `tables.manage` + `integrations.manage`; Anzahlungen `tables.manage` + `payments.manage` (Karte Erstattung `opening_hours.manage`); API-Schlüssel `api_tokens.manage`; Webhooks `webhooks.manage`.
- Code und Bezeichner englisch, Oberflächentexte deutsch mit echten Umlauten, Anrede „du“. Texte im Stil „Sie“ auf den berührten Seiten werden zu „du“.
- Oberflächentexte und Code-Kommentare ohne Negativabgrenzungen („X statt Y“, „kein X, sondern Y“, „X – ohne Y“); eine Verneinung nur, wenn sie selbst die Information ist.
- Fehlermeldungen nennen Ursache und nächsten Schritt. Statuscode oder Kennung dürfen als Zusatz dastehen; Pfade, Stacktraces und Zugangsdaten nie. Wo Laravel-Standardtexte erscheinen würden, eigene Meldungen übergeben.
- Nichts fest im Code: Verhaltenswerte stehen mit Vorgabe und Grenzen in `config/swayy.php` (über `App\Support\EnvSetting`), dazu ein Eintrag in `.env.example`; die Navigation in `config/navigation.php`. Tests prüfen auch mit anderen Werten als der Vorgabe. Jede Grenze passt zu ihrer Spalte unter PostgreSQL (`unsignedSmallInteger` und `unsignedTinyInteger` werden `smallint`, höchstens 32767; `string` ist `varchar(255)`).
- Neuer und berührter Code nutzt keine `onclick`/`onchange`/`onsubmit`-Attribute.
- Formulare mit `data-savebar` enthalten keine Dateifelder und keine Geheimnisfelder.
- Schalter auf neuen Seiten: `x-checkbox` (verstecktes `0` vor dem Kästchen), Prüfregel `required|boolean`, wenn alle Aufrufer den Schalter senden; sonst `nullable|boolean` mit Kommentar, wer ihn nicht sendet.
- Antworten bestehender Endpunkte behalten ihre Felder; neue Felder wie `message` kommen hinzu.
- Mandantentrennung: jede Abfrage mit `location_id` bzw. `tenant_id`; Einträge anderer Betriebe oder Standorte → 404, fremde IDs in Anfragen → 422 mit Grund.
- Die alte Sammelseite `/admin/settings` bleibt bis Teil 3 bedienbar; ihre Formulare speichern weiter über dieselben Routen und Feldnamen.
- Kein Nutzertext per `innerHTML` ohne Maskierung; Werte über `textContent` oder Blade.
- 2c legt keine Migration an und ändert keine CSP. Ändert ein Task eine Datenverarbeitung, gehört `resources/legal/datenschutz.md` in denselben Commit.
- Barrierefreiheit WCAG 2.2 AA: sichtbarer Fokus, Klickziele ≥ 24 × 24 px, Zustände nie über Farbe allein, Live-Region für Hinweise, Fokus nie unter der Speicherleiste (2.4.11), Grafiken mit Bedeutung ≥ 3:1 Kontrast.
- Restaurants und Salons: Jeder sichtbare Satz passt zu beiden („Reservierung“ bzw. „Termin“ je Betriebsart); Funktionen, die es für Salons nicht gibt, sind dort ausgeblendet, nach Prüfung im Code.
- Tests PHP: `declare(strict_types=1)`, `use CreatesTenants, RefreshDatabase;`, vor Requests `$this->clearTenantContext()`. Keine echten Namen oder Adressen in Testdaten.
- Tests JS: Vitest mit jsdom unter `tests/js/*.test.js`, `npm test`.
- PHP: Imports alphabetisch, keine vollqualifizierten Klassennamen in Code oder Docblocks (Pint `fully_qualified_strict_types`).
- Blade: Auf `</x-slot:…>` folgt nie direkt Text. Komponenten-Attribute mit Variablen über `:name="…"`. Eine Ansicht nutzt entweder nur `@php … @endphp`-Blöcke oder nur die Kurzform `@php(…)`. Vor einer Direktive steht nie ein Buchstabe.
- Jede neu protokollierte Aktion bekommt eine deutsche Bezeichnung in `AuditLog::ACTION_LABELS` (`AuditLabelTest`), jedes neue Feld im Protokoll eine Bezeichnung in `AuditLog::FIELD_LABELS`.
- Arbeitskopie: Worktree `C:\Users\brigh\Claude Workingdir\gastrobook-new-ui`, Branch `new-ui` ohne Upstream. Nie pushen. Nie `git stash`.
- Befehle laufen in Git Bash im Worktree. Tests mit `PAO_DISABLE=1 php artisan test …`. PostgreSQL-Lauf mit eigenem Wegwerf-Container `gastrobook-pgtest-2c` auf Port 55433; zwei Suiten nie gleichzeitig im selben Worktree. Kein Entwicklungsserver, keine Änderung an der lokalen Datenbank.
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Geheimnisse bleiben geheim:** Gespeicherte Schlüssel (Stripe, PayPal, seven.io, MailWizz) erscheinen nie im HTML, auch nicht als `value` oder Platzhalter; ein leeres Feld behält den Wert, ein ausgefülltes ersetzt ihn. Getestet in Task 2, 3 und 4.
2. **Alte Sammelseite speichert weiter:** Erinnerung, Feedback, Erstattung, Absender, SMS, Zahlungsanbieter, Newsletter und Anzahlungsregeln lassen sich über die alten Formulare mit denselben Feldnamen speichern, auch wenn Schalter fehlen (alte Kästchen senden nichts). Getestet in Task 1, 2, 3, 4.
3. **Speicherwege schreiben nur ihre Felder:** Benachrichtigungen ändern keine Buchungsregel, die Erstattungskarte keine Benachrichtigung; nicht gesendete Werte bleiben. Getestet in Task 1 und 3.
4. **Fehlerseiten verraten nichts:** 500 zeigt keine Ausnahme, keinen Pfad, keine SQL; 403 zeigt den Text aus `abort(403, …)`; 419 nennt „Seite neu laden“. Getestet in Task 5.
5. **Passwort ändern:** falsches aktuelles Passwort → Text am Feld, kein Wechsel; neues Passwort unter der Mindestlänge (auch mit anderer Einstellung) → Text am Feld; Erfolg → Anmeldung mit neuem Passwort klappt, mit altem nicht; Eintrag im Protokoll ohne Passwort. Getestet in Task 5.

## Dateien

| Datei | Aufgabe |
|---|---|
| `app/Http/Controllers/Admin/Settings/NotificationsController.php`, `resources/views/admin/settings/notifications.blade.php`, `tests/Feature/NotificationsPageTest.php` | Task 1 |
| `app/Http/Controllers/Admin/NotificationTemplateController.php`, `resources/views/admin/templates/index.blade.php`, `app/Http/Controllers/Admin/Settings/SenderController.php`, `resources/views/admin/settings/sender.blade.php`, `app/Http/Controllers/Admin/Settings/SmsController.php`, `resources/views/admin/settings/sms.blade.php`, Tests `TemplatesPageTest`, `SenderPageTest`, `SmsPageTest` | Task 2 |
| `app/Http/Controllers/Admin/Settings/PaymentProvidersController.php`, `resources/views/admin/settings/payment-providers.blade.php`, `app/Http/Controllers/Admin/Settings/DepositsController.php`, `resources/views/admin/settings/deposits.blade.php`, `resources/views/admin/settings/partials/deposit-rule-fields.blade.php`, Tests `PaymentProvidersPageTest`, `DepositsPageTest` | Task 3 |
| `app/Http/Controllers/Admin/Settings/NewsletterController.php`, `resources/views/admin/settings/newsletter.blade.php`, `app/Http/Controllers/Admin/ApiTokenController.php`, `resources/views/admin/api-tokens/index.blade.php`, `app/Http/Controllers/Admin/WebhookController.php`, `resources/views/admin/webhooks/index.blade.php`, Tests `NewsletterPageTest`, `ApiTokensPageTest`, `WebhooksPageTest` | Task 4 |
| `resources/views/errors/layout.blade.php`, `resources/views/errors/{403,404,419,429,500,503}.blade.php`, `app/Http/Controllers/Admin/AccountController.php`, `resources/views/admin/account/index.blade.php`, Tests `ErrorPagesTest`, `AccountPasswordTest` | Task 5 |
| `app/Http/Controllers/Admin/AuditLogController.php`, `app/Models/AuditLog.php`, `resources/views/admin/audit/index.blade.php`, Tests `AuditLogFilterTest`, `AuditFieldLabelTest`, `AuditLabelTest` | Task 6 |
| `app/Http/Controllers/Admin/SettingsController.php` | Task 1–4 (Speicherwege ziehen aus; die Sammelseite liest Grenzen aus der Konfiguration) |
| `config/swayy.php`, `.env.example`, `routes/web.php`, `config/navigation.php`, `app/Models/AuditLog.php` | Task 1–6 je ihr Abschnitt |

---

### Task 0: Abgleich mit `main` (erledigt)

Merge aabd562 „Merge main (1.134.8) in new-ui“: übernimmt 1.134.7 (Gäste nur über die E-Mail-Adresse zuordnen) und 1.134.8. Konflikte gelöst (GuestAuthService: Empfänger aus `main`, Anzeigename aus new-ui; Teamseite, Reservierungsdetail und UserManagementController in der Fassung von new-ui; Changelog mit beiden Teilen). SQLite 1529 grün, Pint und PHPStan ohne Befund.


### Task 1: Seite „Benachrichtigungen“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/NotificationsController.php`, `resources/views/admin/settings/notifications.blade.php`, `tests/Feature/NotificationsPageTest.php`
- Modify: `config/swayy.php` (Abschnitt `notifications`), `.env.example`, `routes/web.php`, `config/navigation.php` (Eintrag `notifications`), `app/Models/AuditLog.php`, `app/Http/Controllers/Admin/SettingsController.php` (`updateBookingRules` liest die Grenzen aus der Konfiguration)
- Test: `tests/Feature/SettingsFormErrorsTest.php`, `tests/Feature/OwnerNotificationTest.php`, `tests/Feature/NavigationTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-page-header`, `x-settings.section`, `x-save-bar`, `x-field :minutes`, `x-checkbox`, `data-show-if` (2a/2b); `RespondsToSettingsForms`; `LocationSettings::du()`.
- Produces:
  - Routen `admin.settings.notifications.show` (GET `/admin/settings/notifications`) und `admin.settings.notifications` (PUT), beide `permission:opening_hours.manage` in der Gruppe `permission:tables.manage`.
  - Sprungziele `#anrede`, `#bestaetigung`, `#erinnerung`, `#feedback`, `#betrieb`.
  - Protokollaktion `location.notifications_updated`.
  - `config('swayy.notifications.reminder_hours_min')` (Vorgabe 1), `reminder_hours_max` (168), `feedback_hours_min` (1), `feedback_hours_max` (336), `feedback_url_max` (255), `owner_email_max` (200), alle int.
  - Link „Text bearbeiten“: `route('admin.templates.index').'#vorlage-'.$key` (Task 2 baut die Seitenleisten `vorlage-{key}`).

- [ ] **Step 1: Failing test – `tests/Feature/NotificationsPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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
```

- [ ] **Step 2: Test laufen lassen, er scheitert**

Run: `PAO_DISABLE=1 php artisan test --filter=NotificationsPageTest`
Expected: FAIL mit 404 für `/admin/settings/notifications` (Route fehlt).

- [ ] **Step 3: Einstellungen in `config/swayy.php`**

Neuer Abschnitt `notifications` nach dem Abschnitt `booking` (Muster der übrigen Abschnitte, Kommentar je Wert):

```php
    /*
    | Benachrichtigungen an Gäste und den Betrieb (Einstellungen › Benachrichtigungen,
    | dieselben Grenzen auf der alten Sammelseite).
    | reminder_hours_*: Erinnerung so viele Stunden vor dem Besuch (Spalte smallint).
    | feedback_hours_*: Feedback-Anfrage so viele Stunden nach dem Besuch (smallint).
    | feedback_url_max: Länge des Links zum Bewertungsportal (Spalte varchar(255)).
    | owner_email_max: Länge der Adresse für die Info an den Betrieb.
    */
    'notifications' => [
        'reminder_hours_min' => EnvSetting::integer('SWAYY_REMINDER_HOURS_MIN', default: 1, min: 1, max: 24),
        'reminder_hours_max' => EnvSetting::integer('SWAYY_REMINDER_HOURS_MAX', default: 168, min: 24, max: 720),
        'feedback_hours_min' => EnvSetting::integer('SWAYY_FEEDBACK_HOURS_MIN', default: 1, min: 1, max: 24),
        'feedback_hours_max' => EnvSetting::integer('SWAYY_FEEDBACK_HOURS_MAX', default: 336, min: 24, max: 720),
        'feedback_url_max' => EnvSetting::integer('SWAYY_FEEDBACK_URL_MAX', default: 255, min: 30, max: 255),
        'owner_email_max' => EnvSetting::integer('SWAYY_OWNER_EMAIL_MAX', default: 200, min: 50, max: 255),
    ],
```

`.env.example` bekommt die sechs Werte mit Bereich und einem Satz je Wert im Block der übrigen `SWAYY_*`-Einstellungen.

- [ ] **Step 4: Prüfregeln an einer Stelle – `App\Support\NotificationSettingRules`**

Neue Datei `app/Support/NotificationSettingRules.php` (Muster `BookingSettingRules`):

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prüfregeln und Meldungen für Benachrichtigungen. Die neue Seite und die
 * alte Sammelseite (SettingsController::updateBookingRules) nutzen dieselben.
 */
final class NotificationSettingRules
{
    /**
     * @param  bool  $switchesRequired  true auf der neuen Seite (verstecktes 0), false auf der alten Sammelseite (fehlendes Kästchen = aus)
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $switchesRequired = true): array
    {
        $limits = (array) config('swayy.notifications');
        $switch = $switchesRequired ? ['required', 'boolean'] : ['nullable', 'boolean'];

        return [
            'guest_address' => ['required', 'in:du,Sie'],
            'reminder_enabled' => $switch,
            'reminder_hours_before' => ['required', 'integer', 'min:'.$limits['reminder_hours_min'], 'max:'.$limits['reminder_hours_max']],
            'sms_reminder_enabled' => $switch,
            'feedback_enabled' => $switch,
            'feedback_hours_after' => ['required', 'integer', 'min:'.$limits['feedback_hours_min'], 'max:'.$limits['feedback_hours_max']],
            'feedback_redirect_min_score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback_external_url' => ['nullable', 'string', 'max:'.$limits['feedback_url_max'], 'url:https'],
            'owner_notification_enabled' => $switch,
            'owner_notification_email' => ['nullable', 'email:rfc', 'max:'.$limits['owner_email_max']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'guest_address.required' => __('Bitte „du“ oder „Sie“ wählen.'),
            'guest_address.in' => __('Bitte „du“ oder „Sie“ wählen.'),
            'reminder_hours_before.required' => __('Bitte eintragen, wie viele Stunden vorher die Erinnerung kommt.'),
            'reminder_hours_before.integer' => __('Bitte die Stunden als ganze Zahl eintragen.'),
            'reminder_hours_before.min' => __('Die Erinnerung kommt frühestens :min Stunde vorher. Bitte mindestens :min eintragen.'),
            'reminder_hours_before.max' => __('Die Erinnerung kommt höchstens :max Stunden vorher. Bitte höchstens :max eintragen.'),
            'feedback_hours_after.required' => __('Bitte eintragen, wie viele Stunden nach dem Besuch die Anfrage kommt.'),
            'feedback_hours_after.integer' => __('Bitte die Stunden als ganze Zahl eintragen.'),
            'feedback_hours_after.min' => __('Die Feedback-Anfrage kommt frühestens :min Stunde nach dem Besuch. Bitte mindestens :min eintragen.'),
            'feedback_hours_after.max' => __('Die Feedback-Anfrage kommt höchstens :max Stunden nach dem Besuch. Bitte höchstens :max eintragen.'),
            'feedback_redirect_min_score.*' => __('Bitte eine Zahl von 1 bis 5 Sternen wählen.'),
            'feedback_external_url.url' => __('Der Link zum Bewertungsportal muss mit https:// beginnen. Bitte den Link aus deinem Bewertungsportal kopieren.'),
            'feedback_external_url.max' => __('Der Link zum Bewertungsportal darf höchstens :max Zeichen haben. Bitte einen kürzeren Link verwenden, etwa den Kurzlink des Portals.'),
            'owner_notification_email.email' => __('Die Adresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: team@dein-betrieb.de'),
            'owner_notification_email.max' => __('Die Adresse darf höchstens :max Zeichen haben.'),
            '*.boolean' => __('Bitte den Schalter ein- oder ausschalten. Steht er nicht auf der Seite, die Seite neu laden.'),
            '*.required' => __('Bei „:attribute“ fehlt ein Wert. Bitte einen Wert eintragen oder wählen; steht das Feld nicht auf der Seite, die Seite neu laden.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'guest_address' => __('Anrede'),
            'reminder_enabled' => __('E-Mail-Erinnerung'),
            'reminder_hours_before' => __('Stunden vorher'),
            'sms_reminder_enabled' => __('SMS-Erinnerung'),
            'feedback_enabled' => __('Feedback-Anfrage'),
            'feedback_hours_after' => __('Stunden nach dem Besuch'),
            'feedback_redirect_min_score' => __('Ab Sternen zum Portal'),
            'feedback_external_url' => __('Link zum Bewertungsportal'),
            'owner_notification_enabled' => __('Info an den Betrieb'),
            'owner_notification_email' => __('Adresse für die Info'),
        ];
    }
}
```

Hinweis zu `'*.boolean'` und `'*.required'`: Laravel nimmt Platzhalter-Schlüssel nur für Feldnamen mit Punkt. Wenn die Meldungen für die flachen Feldnamen nicht greifen (Test zeigt Laravel-Standardtext), dieselben Texte je Feld ausschreiben (`reminder_enabled.boolean` …), wie `BookingSettingRules::messages()` es mit einer Schleife tut.

- [ ] **Step 5: Controller `NotificationsController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Support\NotificationSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Gästekommunikation › Benachrichtigungen: Anrede, Bestätigung,
 * Erinnerung per E-Mail und SMS, Feedback-Anfrage und Info an den Betrieb.
 * Gilt für den gewählten Standort.
 */
class NotificationsController extends Controller
{
    use RespondsToSettingsForms;

    /** Felder, die diese Seite schreibt. */
    public const FIELDS = [
        'guest_address', 'reminder_enabled', 'reminder_hours_before', 'sms_reminder_enabled',
        'feedback_enabled', 'feedback_hours_after', 'feedback_redirect_min_score', 'feedback_external_url',
        'owner_notification_enabled', 'owner_notification_email',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return view('admin.settings.notifications', [
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'salon' => $this->context->tenant()->isSalon(),
            'limits' => (array) config('swayy.notifications'),
            'smsReady' => IntegrationConnection::whereNull('location_id')
                ->where('provider', 'sevenio')
                ->where('status', 'connected')
                ->exists(),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(
            NotificationSettingRules::rules(),
            NotificationSettingRules::messages(),
            NotificationSettingRules::attributes(),
        );
        foreach (['reminder_enabled', 'sms_reminder_enabled', 'feedback_enabled', 'owner_notification_enabled'] as $switch) {
            $validated[$switch] = $request->boolean($switch);
        }
        $validated['feedback_external_url'] = trim((string) ($validated['feedback_external_url'] ?? '')) ?: null;
        $validated['owner_notification_email'] = trim((string) ($validated['owner_notification_email'] ?? '')) ?: null;

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(self::FIELDS);
        $settings->update($validated);

        $this->audit->log('location.notifications_updated', $settings, $old, $validated);

        return $this->saved($request, __('Benachrichtigungen gespeichert.'));
    }
}
```

- [ ] **Step 6: Ansicht `resources/views/admin/settings/notifications.blade.php`**

Aufbau nach `resources/views/admin/settings/rules.blade.php` (Seitenkopf mit `:crumb="['Einstellungen', 'Gästekommunikation']"`, `scope="location"`, Speicherleiste mit `one="1 Einstellung geändert" many=":count Einstellungen geändert"`, ein Formular `data-ajax data-savebar` mit PUT auf `admin.settings.notifications`). Fünf Abschnitte `x-settings.section`:

| id | Titel | Inhalt |
|---|---|---|
| `anrede` | Anrede | Zwei Auswahlkarten `guest_address` (`du` „du“ mit Beispielsatz „Hallo Anna, deine Reservierung …“, `Sie` „Sie“ mit „Guten Tag Frau Muster, Ihre Reservierung …“); Erklärung links: „Gilt für Buchungsseite, Mails und SMS an Gäste.“ Auswahl liest `old('guest_address', $settings->guest_address)`; Fehlerzeile mit `aria-describedby` wie auf `rules.blade.php` |
| `bestaetigung` | Bestätigung | Ein Satz: „Nach jeder Buchung bekommt der Gast eine Bestätigung, bei Freigabe von Hand zuerst eine Eingangsbestätigung.“ Links „Text der Bestätigung bearbeiten“ (`#vorlage-reservation_confirmed`) und „Text der Eingangsbestätigung bearbeiten“ (`#vorlage-reservation_requested`) |
| `erinnerung` | Erinnerung | `x-checkbox reminder_enabled` „E-Mail-Erinnerung schicken“; `x-field reminder_hours_before` Typ number, Einheit „Std.“, `min`/`max` aus `$limits`, Hilfe „So viele Stunden vor der Reservierung kommt die Erinnerung.“ (Salon: „vor dem Termin“); `x-checkbox sms_reminder_enabled` „Erinnerung zusätzlich per SMS“ mit Hilfe „Geht nur an Gäste mit Telefonnummer.“; ohne verbundenes seven.io darunter `<p>` „SMS-Versand ist noch nicht eingerichtet.“ mit Link „SMS-Versand einrichten“ auf `route('admin.settings.sms.show')`; Link „Text der Erinnerung bearbeiten“ (`#vorlage-reservation_reminder`) |
| `feedback` | Feedback nach dem Besuch | `x-checkbox feedback_enabled`; `x-field feedback_hours_after` (Einheit „Std.“, Grenzen aus `$limits`); Auswahl `feedback_redirect_min_score` 1–5 („ab 4 Sternen zum Portal“); `x-field feedback_external_url` Typ url, `maxlength` aus `$limits['feedback_url_max']`, Hilfe „Ab dieser Sternezahl leitet Swayy zufriedene Gäste dorthin weiter. Ohne Link bleibt jedes Feedback bei dir.“; Link „Text der Feedback-Anfrage bearbeiten“ (`#vorlage-feedback_request`) |
| `betrieb` | Info an den Betrieb | `x-checkbox owner_notification_enabled` „Bei jeder neuen Reservierung eine Mail an den Betrieb“ (Salon: „Termin“); `x-field owner_notification_email` Typ email, Platzhalter = Kontaktadresse des Standorts, Hilfe „Leer: an die Kontaktadresse des Standorts (‹Adresse›).“ bzw. ohne Kontaktadresse in Bernstein „Bitte eintragen. Ohne Adresse hier und am Standort kommt die Info nirgends an.“ |

Felder, die nur bei eingeschaltetem Schalter wirken, stehen in einem Block mit `data-show-if="reminder_enabled:1"` usw. (Anfangszustand `hidden` aus `old()`).

- [ ] **Step 7: Routen, Navigation, Protokoll, alte Sammelseite**

`routes/web.php` in der Gruppe `permission:tables.manage` neben den 2b-Seiten:

```php
        Route::get('/settings/notifications', [NotificationsController::class, 'show'])
            ->middleware('permission:opening_hours.manage')->name('settings.notifications.show');
        Route::put('/settings/notifications', [NotificationsController::class, 'update'])
            ->middleware('permission:opening_hours.manage')->name('settings.notifications');
        // Vorläufig bis Task 2: Ziel für Links auf den SMS-Versand.
        Route::get('/settings/sms', fn () => redirect()->to(route('admin.settings.index').'#integrationen'))
            ->middleware('permission:integrations.manage')->name('settings.sms.show');
```

`config/navigation.php`, Eintrag `notifications`: `'route' => 'admin.settings.notifications.show'`, ohne `fragment`/`active => []`, `'can' => ['tables.manage', 'opening_hours.manage']`, Suchbegriffe mit Sprungziel (`'Erinnerung' => 'erinnerung'`, `'Bestätigungsmail' => 'bestaetigung'`, `'Feedback' => 'feedback'`, `'Benachrichtigung an den Betrieb' => 'betrieb'`, `'Anrede' => 'anrede'`, `'du' => 'anrede'`, `'Sie' => 'anrede'`) im Format der übrigen Einträge.

`AuditLog::ACTION_LABELS`: `'location.notifications_updated' => 'Benachrichtigungen geändert'`. `AuditLog::FIELD_LABELS` für die zehn Felder aus `NotificationSettingRules::attributes()` (fehlende ergänzen).

`SettingsController::updateBookingRules`: Die Regeln für die zehn Felder kommen aus `NotificationSettingRules::rules(switchesRequired: false)`, die Meldungen werden mit `BookingSettingRules::messages()` zusammengeführt (`array_merge`). Die Sammelseite liest `min`/`max` der Felder `reminder_hours_before` und `feedback_hours_after` aus `config('swayy.notifications')`.

- [ ] **Step 8: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="NotificationsPageTest|SettingsFormErrorsTest|OwnerNotificationTest|NavigationTest|AuditLabelTest|BladePhpBlockTest"`
Expected: PASS. Danach die ganze Suite einmal, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/NotificationsController.php app/Support/NotificationSettingRules.php resources/views/admin/settings/notifications.blade.php tests/Feature/NotificationsPageTest.php config/swayy.php .env.example routes/web.php config/navigation.php app/Models/AuditLog.php app/Http/Controllers/Admin/SettingsController.php
git commit -m "feat(settings): Seite „Benachrichtigungen“ mit Anrede, Erinnerung, Feedback und Info an den Betrieb

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```


### Task 2: Vorlagen, Absender & Antworten, SMS-Versand

**Files:**
- Modify: `app/Http/Controllers/Admin/NotificationTemplateController.php`, `resources/views/admin/templates/index.blade.php`, `app/Http/Controllers/Admin/SettingsController.php` (`updateGuestMail`, `updateSms` ziehen aus), `routes/web.php`, `config/navigation.php` (Einträge `templates`, `sender`, `sms`), `config/swayy.php` (Abschnitt `templates`), `.env.example`, `app/Models/AuditLog.php`
- Create: `app/Http/Controllers/Admin/Settings/SenderController.php`, `resources/views/admin/settings/sender.blade.php`, `app/Http/Controllers/Admin/Settings/SmsController.php`, `resources/views/admin/settings/sms.blade.php`, `resources/views/admin/templates/partials/fields.blade.php`, `tests/Feature/TemplatesPageTest.php`, `tests/Feature/SenderPageTest.php`, `tests/Feature/SmsPageTest.php`
- Test: `tests/Feature/NotificationTemplateTest.php`, `tests/Feature/GuestMailReplyToTest.php`, `tests/Feature/SmsIntegrationTest.php`, `tests/Feature/TemplatedMailTest.php`, `tests/Feature/GuestMailDesignTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-drawer`, `x-inline-confirm :reload`, `x-status-icon`, `x-empty-state`, `x-checkbox`, `x-field`, `copy.js`; `RespondsToSettingsForms`; `GuestMailSender::fromName()`/`replyTo()`; `GuestReplyAddress::isConfigured()`; `SevenIoProvider::testConnection()`.
- Produces:
  - Vorlagen: Seitenleisten `vorlage-{key}` (Task 1 verlinkt `#vorlage-reservation_reminder` usw.); `update` und `reset` antworten per `RespondsToSettingsForms` (JSON `message`, `reload`), sonst Weiterleitung wie bisher.
  - `config('swayy.templates.subject_max')` (Vorgabe 200), `config('swayy.templates.body_max')` (Vorgabe 5000).
  - Absender & Antworten: GET `admin.settings.sender.show` (`/admin/settings/sender`), PUT `admin.settings.guest-mail` (Adresse unverändert) → `SenderController::update`. Sprungziele `#absender`, `#antworten`.
  - SMS-Versand: GET `admin.settings.sms.show` (`/admin/settings/sms`, ersetzt die vorläufige Weiterleitung aus Task 1), PUT `admin.settings.sms` → `SmsController::update`. Sprungziel `#seven`.

- [ ] **Step 1: Failing tests**

`tests/Feature/TemplatesPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TemplatesPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_page_lists_every_template_with_its_state_and_drawer(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        NotificationTemplate::create(['tenant_id' => $setup['tenant']->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'Bis morgen', 'body' => 'Hallo {guest_name}', 'is_active' => true]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/templates')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="vorlage-reservation_reminder"', false)
            ->assertSee('id="vorlage-feedback_request"', false)
            ->assertSee('Angepasst')
            ->assertSee('Standard')
            ->assertSee('{guest_name}')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="templates"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_saving_answers_with_json_for_the_drawer(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => 'Bis morgen', 'body' => 'Hallo {guest_name}'])
            ->assertOk()
            ->assertJsonPath('message', 'Vorlage „Erinnerung“ gespeichert.')
            ->assertJsonPath('reload', true);
    }

    public function test_limits_come_from_the_settings_and_name_the_next_step(): void
    {
        config(['swayy.templates.subject_max' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => str_repeat('x', 21), 'body' => 'Text'])
            ->assertStatus(422);

        $this->assertSame('Der Betreff darf höchstens 20 Zeichen haben. Bitte kürzen.', $response->json('errors.subject.0'));
        $this->actingAs($owner)->get('/admin/templates')->assertSee('maxlength="20"', false);
    }

    public function test_an_unknown_template_answers_404(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/templates/gibt_es_nicht', ['subject' => 'x', 'body' => 'y'])->assertNotFound();
    }

    public function test_reset_answers_with_json_and_removes_only_this_business(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach ([$setup['tenant'], $other['tenant']] as $tenant) {
            NotificationTemplate::create(['tenant_id' => $tenant->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'S', 'body' => 'B', 'is_active' => true]);
        }
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/templates/reservation_reminder')
            ->assertOk()
            ->assertJsonPath('message', 'Vorlage „Erinnerung“ auf Standard zurückgesetzt.');

        $this->assertSame(1, NotificationTemplate::withoutGlobalScopes()->where('key', 'reservation_reminder')->count());
        $this->assertSame($other['tenant']->id, NotificationTemplate::withoutGlobalScopes()->where('key', 'reservation_reminder')->value('tenant_id'));
    }
}
```

`tests/Feature/SenderPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SenderPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_name_reply_address_and_relay(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/sender')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('id="absender"', false)
            ->assertSee('id="antworten"', false)
            ->assertSee('name="mail_from_name"', false)
            ->assertSee('name="mail_reply_to"', false)
            ->assertSee('name="mail_relay_enabled"', false)
            ->assertSee('data-ajax data-savebar', false);
        $this->assertMatchesRegularExpression('/data-nav-key="sender"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_saving_writes_only_the_three_fields_and_answers_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#123456']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', [
            'mail_from_name' => 'Trattoria Test',
            'mail_reply_to' => 'antwort@example.test',
            'mail_relay_enabled' => '0',
        ])->assertOk()->assertJsonPath('message', 'Absender und Antworten gespeichert.');

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Trattoria Test', $tenant->mail_from_name);
        $this->assertSame('antwort@example.test', $tenant->mail_reply_to);
        $this->assertFalse((bool) $tenant->mail_relay_enabled);
        $this->assertSame('#123456', $tenant->brand_primary_color);
    }

    public function test_the_relay_needs_a_configured_domain_and_says_so(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => '', 'mail_reply_to' => '', 'mail_relay_enabled' => '1'])
            ->assertStatus(422);

        $this->assertStringContainsString('Bitte wende dich an den Betreiber', $response->json('errors.mail_relay_enabled.0'));
    }

    public function test_staff_without_settings_rights_is_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/sender')->assertForbidden();
    }
}
```

`tests/Feature/SmsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SmsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_page_shows_the_state_and_never_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'sevenio', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'geheimer-sms-schluessel', 'sender_id' => 'Trattoria'])),
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/sms')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('id="seven"', false)
            ->assertSee('Verbunden')
            ->assertSee('value="Trattoria"', false)
            ->assertDontSee('geheimer-sms-schluessel');
        $this->assertMatchesRegularExpression('/data-nav-key="sms"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_key_keeps_the_stored_one(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'sevenio', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'alter-schluessel', 'sender_id' => 'Alt'])),
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Neu', 'enabled' => '1'])->assertOk();

        $connection = IntegrationConnection::where('provider', 'sevenio')->first();
        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        $this->assertSame('alter-schluessel', $credentials['api_key']);
        $this->assertSame('Neu', $credentials['sender_id']);
    }

    public function test_a_missing_key_names_where_to_find_it(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => '', 'enabled' => '1'])->assertStatus(422);

        $this->assertStringContainsString('app.seven.io', $response->json('errors.api_key.0'));
    }

    public function test_a_too_long_sender_id_names_the_limit(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'k', 'sender_id' => str_repeat('A', 17), 'enabled' => '1'])->assertStatus(422);

        $this->assertStringContainsString('16', $response->json('errors.sender_id.0'));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie scheitern**

Run: `PAO_DISABLE=1 php artisan test --filter="TemplatesPageTest|SenderPageTest|SmsPageTest"`
Expected: FAIL (Seiten fehlen, Vorlagen antworten mit Weiterleitung).

- [ ] **Step 3: Vorlagen**

`config/swayy.php`, neuer Abschnitt `templates` (Kommentar: „Länge von Betreff und Text einer eigenen Mailvorlage; Spalten `subject` varchar(255), `body` text“) mit `subject_max` (`SWAYY_TEMPLATE_SUBJECT_MAX`, Vorgabe 200, Bereich 20–255) und `body_max` (`SWAYY_TEMPLATE_BODY_MAX`, Vorgabe 5000, Bereich 500–20000), `.env.example` dazu. Spaltentypen vorher in der Migration von `notification_templates` prüfen; liegt `subject` unter 255, die Obergrenze anpassen.

`NotificationTemplateController`:
- `use RespondsToSettingsForms;`
- `index()` gibt zusätzlich `limits` (`config('swayy.templates')`) und `salon` an die Ansicht.
- `update()` prüft mit `max` aus der Konfiguration und eigenen Meldungen: `subject.required` „Bitte einen Betreff eintragen.“, `subject.max` „Der Betreff darf höchstens :max Zeichen haben. Bitte kürzen.“, `body.required` „Bitte einen Text eintragen. Zum Zurücksetzen auf den Standard gibt es „Auf Standard zurücksetzen“.“, `body.max` „Der Text darf höchstens :max Zeichen haben. Bitte kürzen.“ und antwortet `return $this->saved($request, __('Vorlage „:name“ gespeichert.', ['name' => self::LABELS[$key]]), reload: true);`
- `reset(Request $request, string $key)` antwortet `return $this->saved($request, __('Vorlage „:name“ auf Standard zurückgesetzt.', …), reload: true);`. Die Löschabfrage bleibt auf den Betrieb begrenzt.
- Typografische Anführungszeichen „…“ in allen Meldungen (heute `„:name"`).

Ansicht `resources/views/admin/templates/index.blade.php` neu:
- `x-page-header title="Vorlagen" :crumb="['Einstellungen', 'Gästekommunikation']" scope="tenant" description="Betreff und Text der Mails an Gäste. Ohne eigene Fassung schickt Swayy den Standardtext."`.
- Abschnitt „Platzhalter“: Liste der Platzhalter als `<code>` mit einem Satz: „Swayy ersetzt sie beim Versand durch die Angaben der Buchung.“ Was für den Versand gilt (Mail-Rahmen mit Logo und Fußbereich seit dem Mail-Design), im Code nachsehen (`NotificationTemplateRenderer`, `TemplatedMailTest`) und in einem Satz richtig beschreiben; die heutige Aussage „E-Mails werden als reiner Text versendet“ entfällt, falls sie nicht mehr stimmt.
- Liste `<ul>` mit einer Zeile je Vorlage: Bezeichnung (Knopf `data-drawer-open="vorlage-{key}"`), `x-status-icon` mit Wort „Angepasst“ (Zustand `ok`) bzw. „Standard“ (Zustand `neutral` oder die passende Form aus `x-status-icon`), `:announce="false"` weil das Wort daneben steht; bei angepassten Vorlagen `x-inline-confirm id="vorlage-zuruecksetzen-{key}"` mit `:reload="true"`, Frage „Vorlage „‹Bezeichnung›“ auf den Standardtext zurücksetzen? Deine Fassung geht dabei verloren.“
- Je Vorlage `x-drawer id="vorlage-{key}" :title="$t['label']"` mit Formular `data-ajax` PUT auf `admin.templates.update` und dem Partial `templates/partials/fields.blade.php`: `x-field subject` (`maxlength` aus `$limits`), `textarea body` mit Beschriftung, `maxlength` aus `$limits`, Hilfe „Platzhalter in geschweiften Klammern ersetzt Swayy beim Versand.“; Fußknopf „Speichern“.
- Keine `onsubmit`-Attribute mehr.

- [ ] **Step 4: Absender & Antworten**

`SenderController` (`App\Http\Controllers\Admin\Settings`), `show()` übergibt `tenant`, `location`, `fallbackName` und `fallbackReply` (Berechnung wie heute in `settings/index.blade.php` Zeilen 132–137, als Methode im Controller), `relayReady` (`GuestReplyAddress::isConfigured()`), `relayDomain` (`config('swayy.guest_mail_relay.domain')`).

`update()` übernimmt `SettingsController::updateGuestMail` unverändert in der Sache, mit diesen Änderungen:
- Schalter: `'mail_relay_enabled' => ['nullable', 'boolean']` mit Kommentar „Die alte Sammelseite sendet das Kästchen nur angehakt.“
- Ablehnung der Weiterleitung über `$this->failed($request, 'mail_relay_enabled', …)` (heute `back()->withErrors`, das ergab per `fetch` keine 422).
- Erfolg: `$this->saved($request, __('Absender und Antworten gespeichert.'), reload: true)`.
- `SettingsController::updateGuestMail` entfällt; die Route `settings.guest-mail` zeigt auf `SenderController::update`.

Ansicht `settings/sender.blade.php`: Seitenkopf (`crumb` Gästekommunikation, `scope="tenant"`), Speicherleiste, ein Formular `data-ajax data-savebar` PUT `admin.settings.guest-mail`, Abschnitte:
- `absender` „Absender“: `x-field mail_from_name` (`maxlength=100`, Platzhalter = Ersatzname, Hilfe „Leer: „‹Ersatzname›“.“).
- `antworten` „Antworten von Gästen“: `x-field mail_reply_to` Typ email (Hilfe wie heute: Ersatzadresse oder Bernstein-Hinweis „Bitte eintragen. Solange hier und am Standort keine Adresse steht, erreichen Antworten von Gästen niemanden.“); `x-checkbox mail_relay_enabled` „Antworten über Swayy sammeln“ mit Hilfe „Antworten erscheinen im Buchungsverlauf und gehen zusätzlich an deine Adresse.“; ohne eingerichtete Domain ist das Kästchen `disabled` und darunter steht „Diese Funktion richtet der Betreiber von Swayy ein. Bitte frag dort nach.“

- [ ] **Step 5: SMS-Versand**

`SmsController` (`App\Http\Controllers\Admin\Settings`), `show()` übergibt `connection` (seven.io-Verbindung des Betriebs oder null), `senderId` (aus den entschlüsselten Zugangsdaten, nur dieser Wert), `hasKey` (bool), `statusLabel` („Verbunden“, „Ausgeschaltet“, „Fehler beim letzten Test“, „Nicht eingerichtet“).

`update()` übernimmt `SettingsController::updateSms` mit diesen Änderungen:
- Prüfregeln mit eigenen Meldungen: `sender_id.max` „Die Absenderkennung darf höchstens 16 Zeichen haben (11 bei Buchstaben). Bitte kürzen.“, `api_key.max` „Der API-Key ist zu lang. Bitte ihn aus app.seven.io kopieren und ohne Leerzeichen einfügen.“
- `'enabled' => ['nullable', 'boolean']` (alte Sammelseite), Antwort über `saved(…, reload: true)`.
- `SettingsController::updateSms` entfällt, die Route `settings.sms` zeigt auf `SmsController::update`; die vorläufige GET-Route aus Task 1 wird zu `SmsController::show`.

Ansicht `settings/sms.blade.php`: Seitenkopf (`crumb` Gästekommunikation, `scope="tenant"`, Beschreibung „Erinnerungen per SMS über seven.io.“), Abschnitt `seven` „seven.io“ mit Statuszeile (`x-status-icon` + Wort), Formular `data-ajax` (ohne Speicherleiste, Entscheidung „Geheimnisse“) PUT `admin.settings.sms`: Passwortfeld `api_key` (`autocomplete="new-password"`, nie mit Wert; Hilfe bei gespeichertem Schlüssel „Gespeichert. Leer lassen, um ihn zu behalten.“), `x-field sender_id` `maxlength="16"`, `x-checkbox enabled` „SMS-Versand einschalten“, Knopf „Speichern und Verbindung testen“. Erklärung links: wo der Schlüssel steht (app.seven.io → Einstellungen → API), dass Erinnerungen per SMS unter Benachrichtigungen eingeschaltet werden (Link `admin.settings.notifications.show#erinnerung`).

- [ ] **Step 6: Navigation, Protokoll**

`config/navigation.php`: `templates` mit Suchbegriffen auf Seitenleisten (`'Erinnerungstext' => 'vorlage-reservation_reminder'` usw. nach Bedarf); `sender` → `admin.settings.sender.show`, `'can' => ['tables.manage', 'tenant.settings.manage']`, Begriffe mit Ziel `absender`/`antworten`; `sms` → `admin.settings.sms.show`, `'can' => ['tables.manage', 'integrations.manage']`, Begriffe mit Ziel `seven`. Alle ohne `fragment` und `active => []`.

`AuditLog`: bestehende Aktionen `template.updated`, `template.reset`, `tenant.guest_mail_updated`, `integration.sms_updated` behalten ihre Bezeichnungen; Feldnamen `mail_from_name`, `mail_reply_to`, `mail_relay_enabled`, `sender_id` in `FIELD_LABELS`, falls sie fehlen.

- [ ] **Step 7: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="TemplatesPageTest|SenderPageTest|SmsPageTest|NotificationTemplateTest|GuestMailReplyToTest|SmsIntegrationTest|TemplatedMailTest|GuestMailDesignTest|SettingsFormErrorsTest|NavigationTest|AuditLabelTest|NotificationsPageTest"`
Expected: PASS. `NotificationTemplateTest` erwartet heute eine Weiterleitung nach `put()`; das bleibt so (ohne `wantsJson`). Danach ganze Suite, Pint, PHPStan, `npm run build`.

- [ ] **Step 8: Commit**

```bash
git add -A app/Http/Controllers resources/views/admin/templates resources/views/admin/settings/sender.blade.php resources/views/admin/settings/sms.blade.php routes/web.php config/navigation.php config/swayy.php .env.example app/Models/AuditLog.php tests/Feature/TemplatesPageTest.php tests/Feature/SenderPageTest.php tests/Feature/SmsPageTest.php
git commit -m "feat(settings): Vorlagen, Absender & Antworten und SMS-Versand im neuen Seitenmuster

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```


### Task 3: Zahlungsanbieter und Anzahlungen

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/PaymentProvidersController.php`, `resources/views/admin/settings/payment-providers.blade.php`, `app/Http/Controllers/Admin/Settings/DepositsController.php`, `resources/views/admin/settings/deposits.blade.php`, `resources/views/admin/settings/partials/deposit-rule-fields.blade.php`, `app/Support/MoneyInput.php`, `tests/Feature/PaymentProvidersPageTest.php`, `tests/Feature/DepositsPageTest.php`
- Modify: `app/Http/Controllers/Admin/SettingsController.php` (`updateStripe`, `updatePaypal`, `storeDepositRule`, `updateDepositRule`, `deleteDepositRule` und die beiden Hilfsmethoden ziehen aus; `updateBookingRules` liest die Erstattungsregeln aus `DepositsController::refundRules()`), `routes/web.php`, `config/navigation.php` (`payment-providers`, `deposits`), `config/swayy.php` (Abschnitt `deposits`), `.env.example`, `app/Models/AuditLog.php`, `app/Http/Controllers/Admin/ServiceController.php` (Euro-Umrechnung über `MoneyInput`, falls dort eine eigene steht)
- Test: `tests/Feature/StripePaymentTest.php`, `tests/Feature/DepositLifecycleTest.php`, `tests/Feature/ServiceDepositRuleTest.php`, `tests/Feature/RefundTest.php`, `tests/Feature/ServicesPageTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-drawer`, `x-inline-confirm :reload`, `x-status-icon`, `x-empty-state`, `x-checkbox`, `x-field`, `x-save-bar`, `copy.js`; `RespondsToSettingsForms`.
- Produces:
  - Zahlungsanbieter: GET `admin.settings.payment-providers.show` (`/admin/settings/payment-providers`, `permission:integrations.manage`); PUT `admin.settings.stripe` und `admin.settings.paypal` (Adressen unverändert) → `PaymentProvidersController::updateStripe`/`updatePaypal`. Sprungziele `#stripe`, `#paypal`.
  - Anzahlungen: GET `admin.settings.deposits.show` (`/admin/settings/deposits`, `permission:payments.manage`); POST/PUT/DELETE `admin.settings.deposit-rules.*` (Adressen unverändert) → `DepositsController`; PUT `admin.settings.refunds` (`/admin/settings/refunds`, `permission:opening_hours.manage`) → `DepositsController::updateRefunds`. Seitenleisten `regel-neu`, `regel-{id}`; Sprungziele `#regeln`, `#erstattung`.
  - `App\Support\MoneyInput::toMinor(string|int|float $euro): int` (rechnet mit Ziffern in Cent; `"12.5"` → 1250, `"0.10"` → 10) und `MoneyInput::PATTERN` (`'/^\d+(\.\d{1,2})?$/'`).
  - `config('swayy.deposits.amount_max')` (Euro, Vorgabe 10000), `deadline_min` (Minuten, 10), `deadline_max` (10080), `party_size_max` (100), `name_max` (120).
  - Protokollaktion `location.refunds_updated`.

- [ ] **Step 1: Failing tests**

`tests/Feature/PaymentProvidersPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PaymentProvidersPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function connect(int $tenantId, string $provider, array $credentials, string $status = 'connected'): void
    {
        IntegrationConnection::create([
            'tenant_id' => $tenantId, 'location_id' => null, 'provider' => $provider, 'status' => $status,
            'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
        ]);
    }

    public function test_the_page_shows_both_providers_and_never_a_secret(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_geheim', 'webhook_secret' => 'whsec_geheim']);
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'paypal-client-geheim', 'secret' => 'paypal-secret-geheim', 'mode' => 'sandbox'], 'disconnected');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk();

        $response->assertSee('Einstellungen › Zahlungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="stripe"', false)
            ->assertSee('id="paypal"', false)
            ->assertSee(route('webhooks.stripe'))
            ->assertSee('data-copy="'.route('webhooks.stripe').'"', false)
            ->assertDontSee('sk_test_geheim')
            ->assertDontSee('whsec_geheim')
            ->assertDontSee('paypal-client-geheim')
            ->assertDontSee('paypal-secret-geheim')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="payment-providers"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_empty_secrets_keep_the_stored_ones(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_alt', 'webhook_secret' => 'whsec_alt']);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => 'whsec_neu', 'enabled' => '1'])
            ->assertOk();

        $credentials = json_decode(Crypt::decryptString(IntegrationConnection::where('provider', 'stripe')->value('credentials_encrypted')), true);
        $this->assertSame('sk_test_alt', $credentials['secret_key']);
        $this->assertSame('whsec_neu', $credentials['webhook_secret']);
    }

    public function test_missing_paypal_secrets_are_named_at_their_fields(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => '', 'secret' => '', 'mode' => 'live', 'enabled' => '1'])
            ->assertStatus(422);

        $this->assertStringContainsString('Apps & Credentials', $response->json('errors.client_id.0'));
        $this->assertStringContainsString('Apps & Credentials', $response->json('errors.secret.0'));
    }

    public function test_an_unknown_paypal_mode_names_the_choices(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => 'a', 'secret' => 'b', 'mode' => 'test'])->assertStatus(422);

        $this->assertSame('Bitte „Test (Sandbox)“ oder „Live“ wählen.', $response->json('errors.mode.0'));
    }

    public function test_roles_without_integration_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/payment-providers')->assertForbidden();
    }
}
```

`tests/Feature/DepositsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DepositRule;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DepositsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

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
            ->assertJsonPath('message', 'Anzahlungsregel „Gruppen ab 8“ angelegt.');

        $rule = DepositRule::firstOrFail();
        $this->assertSame(10, $rule->amount_per_person_minor);
        $this->assertSame(500, $rule->flat_amount_minor);
        $this->assertSame(90, $rule->payment_deadline_minutes);
        $this->assertSame($setup['location']->id, $rule->location_id);
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

    public function test_an_unknown_refund_mode_names_the_choices(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/refunds', ['refund_mode' => 'immer', 'refund_percent' => 50, 'refund_processing' => 'immediate'])
            ->assertStatus(422);

        $this->assertSame('Bitte „Aus“, „Von Hand freigeben“ oder „Automatisch“ wählen.', $response->json('errors.refund_mode.0'));
    }

    public function test_staff_without_payment_rights_is_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/deposits')->assertForbidden();
    }
}
```

Dazu `tests/Unit/MoneyInputTest.php` mit `"12.5"` → 1250, `"0.10"` → 10, `"0"` → 0, `"10000"` → 1000000, `12.5` (Fließkomma aus JSON) → 1250, und `MoneyInput::PATTERN` lehnt `"12,5"`, `"1e2"`, `"12.345"`, `"-1"` ab.

- [ ] **Step 2: Tests laufen lassen, sie scheitern**

Run: `PAO_DISABLE=1 php artisan test --filter="PaymentProvidersPageTest|DepositsPageTest|MoneyInputTest"`
Expected: FAIL (Seiten, Route `settings.refunds` und `MoneyInput` fehlen).

- [ ] **Step 3: `MoneyInput` und Einstellungen**

`app/Support/MoneyInput.php`: wandelt nach `PATTERN` geprüfte Eingaben über Ganzzahl-Arithmetik in Cent um (Teil vor dem Punkt × 100 + Nachkommastellen, rechts mit 0 aufgefüllt). Fließkommawerte aus JSON zuerst über `rtrim(rtrim(sprintf('%.2F', $value), '0'), '.')` in Text. Prüft `ServiceController` bereits mit eigener Umrechnung (Task 10 aus 2b), dort auf `MoneyInput` umstellen, ohne das Verhalten zu ändern (`ServicesPageTest` bleibt grün).

`config/swayy.php`, Abschnitt `deposits` mit Kommentar je Wert: `amount_max` (`SWAYY_DEPOSIT_AMOUNT_MAX`, Euro, Vorgabe 10000, Bereich 10–21474836, passt in `unsignedInteger` Cent), `deadline_min` (`SWAYY_DEPOSIT_DEADLINE_MIN`, 10, Bereich 5–120), `deadline_max` (`SWAYY_DEPOSIT_DEADLINE_MAX`, 10080, Bereich 60–32767, Spalte smallint), `party_size_max` (`SWAYY_DEPOSIT_PARTY_SIZE_MAX`, 100, Bereich 2–100, Grenze der Buchungswege), `name_max` (`SWAYY_DEPOSIT_NAME_MAX`, 120, Bereich 20–255). `.env.example` dazu.

- [ ] **Step 4: `PaymentProvidersController`**

`show()` übergibt je Anbieter `stripe`/`paypal` (Verbindung oder null), `stripeHasSecret`, `stripeHasWebhookSecret`, `paypalHasClient`, `paypalHasSecret`, `paypalMode`, `stripeWebhookSeen` (wie heute in `SettingsController::index`) und `webhookUrl` (`route('webhooks.stripe')`). Keine Zugangsdaten an die Ansicht außer den Booleschen Angaben und dem PayPal-Modus.

`updateStripe()` und `updatePaypal()` übernehmen die Methoden aus `SettingsController` mit diesen Änderungen: `enabled` `nullable|boolean` (alte Sammelseite), eigene Meldung `mode.in` „Bitte „Test (Sandbox)“ oder „Live“ wählen.“, Erfolg mit `reload: true`. In `SettingsController` entfallen beide Methoden, die Routen zeigen auf den neuen Controller.

Ansicht `settings/payment-providers.blade.php`: Seitenkopf (`crumb` Zahlungen, `scope="tenant"`, Beschreibung „Wie Gäste Anzahlungen online bezahlen.“). Abschnitt `stripe` „Stripe“: Statuszeile (`x-status-icon` + Wort „Verbunden“/„Ausgeschaltet“/„Nicht eingerichtet“), Zeile „Letzte Meldung von Stripe: ‹Datum›“ oder „Stripe hat sich noch nicht gemeldet. Prüfe die Webhook-Adresse im Stripe-Dashboard.“, die Webhook-Adresse als `<code>` mit Kopierknopf `data-copy`, Formular `data-ajax` mit Passwortfeldern `secret_key` und `webhook_secret` (Hilfe bei gespeichertem Wert „Gespeichert. Leer lassen, um ihn zu behalten.“), `x-checkbox enabled` „Online-Zahlung mit Stripe“, Knopf „Speichern“. Abschnitt `paypal` entsprechend mit `client_id`, `secret`, Auswahlkarten `mode` („Test (Sandbox)“, „Live“), `x-checkbox enabled`. Erklärungen links: wo die Schlüssel stehen (Texte aus der heutigen Sammelseite, ohne `?`-Tipps).

- [ ] **Step 5: `DepositsController`**

Methoden:
- `show()`: übergibt `rules` (Regeln des Standorts mit `service`, `room`, sortiert nach Name), `rooms`, `salonServices` (wie heute in `SettingsController::index`: aktive Leistungen und die, an denen eine Regel hängt), `settings` (Erstattung), `salon`, `limits` (`config('swayy.deposits')`), `currency`.
- `store()`, `update()`: Prüfregeln aus einer privaten Methode `ruleRules()` mit Grenzen aus `config('swayy.deposits')`, `amount_per_person`/`flat_amount` mit `regex:`.MoneyInput::PATTERN` und `max` gegen `amount_max` (eigene Meldungen: „Bitte den Betrag in Euro mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 12.50.“, „Der Betrag darf höchstens :max € sein.“), `until_time` nach `from_time` (`after:from_time`, Meldung „„Bis“ liegt vor „Von“. Bitte eine spätere Uhrzeit wählen.“), `weekdays.*` 0–6. Raum und Leistung: fremde ID → `$this->failed($request, 'room_id', __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.'))` bzw. für `service_id` dasselbe mit Leistung. Beträge über `MoneyInput::toMinor`. Schalter `cancel_unpaid_automatically` `required|boolean` (alle Aufrufer: neue Seite mit verstecktem 0; die alte Sammelseite sendet das Kästchen nur angehakt → `nullable|boolean` mit Kommentar). `update()` behält das heutige Verhalten „Feld nicht gesendet = Bindung bleibt“ für `service_id`, `room_id`, `weekdays`. Antworten: „Anzahlungsregel „‹Name›“ angelegt.“ bzw. „gespeichert.“, `reload: true`.
- `destroy(Request, DepositRule)`: 404 für fremde Standorte; bedingtes Löschen (`DepositRule::whereKey()->where('location_id', …)->delete()`), Protokoll nur bei einer gelöschten Zeile, Antwort „Anzahlungsregel „‹Name›“ gelöscht.“ mit `reload: true` (JSON) bzw. Weiterleitung.
- `updateRefunds(Request)`: `public static function refundRules(): array` mit `refund_mode` `in:off,manual,auto`, `refund_percent` 0–100 (Prozent, Format), `refund_processing` `in:immediate,scheduled`, Meldungen „Bitte „Aus“, „Von Hand freigeben“ oder „Automatisch“ wählen.“, „Bitte einen Prozentwert von 0 bis 100 eintragen.“, „Bitte „Sofort“ oder „Gesammelt nach Zeitplan“ wählen.“. Schreibt nur die drei Felder, protokolliert `location.refunds_updated` mit alten und neuen Werten, Antwort „Erstattung gespeichert.“
- `SettingsController::updateBookingRules` nimmt die drei Erstattungsregeln aus `DepositsController::refundRules()`.

Ansicht `settings/deposits.blade.php`: Seitenkopf (`crumb` Zahlungen, `scope="location"`, Aktion „Regel hinzufügen“ mit `data-drawer-open="regel-neu"`).
- Abschnitt `regeln` „Anzahlungsregeln“: Erklärung links („Ab welcher Gruppe, an welchen Tagen und zu welcher Uhrzeit Gäste vorab bezahlen. Greift keine Regel, ist keine Anzahlung fällig.“); ohne Regeln `x-empty-state` mit einer Aktion; sonst Liste mit Zeile je Regel: Name (Knopf öffnet `regel-{id}`), Kurzzeile („12,50 € p. P. · ab 8 Personen · Fr, Sa · ab 18:00 · Raum Terrasse“, Salon: Leistung), Rückfrage `x-inline-confirm` „Regel „‹Name›“ löschen? Neue Buchungen brauchen danach keine Anzahlung mehr nach dieser Regel; offene Zahlungen bleiben.“ mit `:reload="true"`.
- Seitenleisten `regel-neu` und `regel-{id}` mit dem Partial `deposit-rule-fields` (Felder wie heute auf der Sammelseite, als `x-field` mit Einheit „€“ bzw. „Min.“, `step="0.01"`, Grenzen aus `$limits`; Wochentage als Gruppe von `x-checkbox`-Kästchen mit `fieldset`/`legend` „Nur an diesen Tagen“, Hilfe „Kein Haken: an allen Tagen“; Raumauswahl nur für Restaurants mit Räumen, Leistungsauswahl nur für Salons; `x-checkbox cancel_unpaid_automatically` „Unbezahlte Buchungen nach Ablauf der Frist stornieren“).
- Abschnitt `erstattung` „Erstattung der Anzahlung“: eigenes Formular `data-ajax data-savebar` PUT `admin.settings.refunds` mit Auswahlkarten `refund_mode`, `x-field refund_percent` (Einheit „%“, 0–100), Auswahlkarten `refund_processing`; Erklärung „Gilt bei Storno innerhalb der Frist. Nach der Frist und bei Nichterscheinen behält der Betrieb die Anzahlung.“ (Verneinung entfällt, die Aussage steht positiv). Nur sichtbar mit `opening_hours.manage`; ohne das Recht steht dort ein Satz, wer die Erstattung einstellt.

Die Speicherleiste der Seite gehört zur Erstattungskarte; die Regeln speichern je Eintrag über die Seitenleiste (Spec).

- [ ] **Step 6: Routen, Navigation, Protokoll**

`routes/web.php` in der Gruppe `permission:tables.manage`: GET `settings/payment-providers` (`integrations.manage`), GET `settings/deposits` (`payments.manage`), PUT `settings/refunds` (`opening_hours.manage`); die bestehenden Routen `settings.stripe`, `settings.paypal`, `settings.deposit-rules.*` zeigen auf die neuen Controller.

`config/navigation.php`: `payment-providers` → `admin.settings.payment-providers.show`, `'can' => ['tables.manage', 'integrations.manage']`, Begriffe `'Stripe' => 'stripe'`, `'PayPal' => 'paypal'`; `deposits` → `admin.settings.deposits.show`, `'can' => ['tables.manage', 'payments.manage']`, Begriffe `'Anzahlung' => 'regeln'`, `'No-Show-Schutz' => 'regeln'`, `'Rückerstattung' => 'erstattung'`, `'Erstattungsregeln' => 'erstattung'`.

`AuditLog::ACTION_LABELS`: `'location.refunds_updated' => 'Erstattung der Anzahlung geändert'`; `FIELD_LABELS` für `refund_mode`, `refund_percent`, `refund_processing`, `amount_per_person`, `flat_amount`, `payment_deadline_minutes`, `min_party_size`, `cancel_unpaid_automatically`, `weekdays`, `from_time`, `until_time`, falls sie fehlen.

- [ ] **Step 7: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="PaymentProvidersPageTest|DepositsPageTest|MoneyInputTest|StripePaymentTest|DepositLifecycleTest|ServiceDepositRuleTest|RefundTest|ServicesPageTest|SettingsFormErrorsTest|NavigationTest|AuditLabelTest"`
Expected: PASS. Danach ganze Suite, PostgreSQL-Lauf für `DepositsPageTest|PaymentProvidersPageTest`, Pint, PHPStan, `npm run build`.

- [ ] **Step 8: Commit**

```bash
git add -A app/Http/Controllers app/Support/MoneyInput.php resources/views/admin/settings routes/web.php config/navigation.php config/swayy.php .env.example app/Models/AuditLog.php tests/Feature/PaymentProvidersPageTest.php tests/Feature/DepositsPageTest.php tests/Unit/MoneyInputTest.php
git commit -m "feat(settings): Zahlungsanbieter und Anzahlungen im neuen Seitenmuster, Erstattung mit eigenem Speicherweg

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```


### Task 4: Newsletter, API-Schlüssel, Webhooks

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/NewsletterController.php`, `resources/views/admin/settings/newsletter.blade.php`, `tests/Feature/NewsletterPageTest.php`, `tests/Feature/ApiTokensPageTest.php`, `tests/Feature/WebhooksPageTest.php`
- Modify: `app/Http/Controllers/Admin/SettingsController.php` (`updateMailwizz` und Hilfsmethoden ziehen aus), `app/Http/Controllers/Admin/ApiTokenController.php`, `resources/views/admin/api-tokens/index.blade.php`, `app/Http/Controllers/Admin/WebhookController.php`, `resources/views/admin/webhooks/index.blade.php`, `routes/web.php`, `config/navigation.php` (`newsletter`, `api`, `webhooks`), `app/Models/AuditLog.php`, `config/swayy.php` (falls neue Grenzen), `.env.example`
- Test: `tests/Feature/MailwizzTargetCheckTest.php`, `tests/Feature/NewsletterSyncTest.php`, `tests/Feature/WebhookAdminTest.php`, `tests/Feature/WebhookDeliveryTest.php`, `tests/Feature/ApiTokenScopeTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-drawer`, `x-inline-confirm :reload`, `x-status-icon`, `x-empty-state`, `x-checkbox`, `x-field`, `copy.js`; `RespondsToSettingsForms`; `AllowedOutboundUrl`, `MailwizzProvider`.
- Produces:
  - Newsletter: GET `admin.settings.newsletter.show` (`/admin/settings/newsletter`, `permission:integrations.manage`, Gruppe `tables.manage`), PUT `admin.settings.mailwizz` → `NewsletterController::update`. Sprungziel `#mailwizz`.
  - API-Schlüssel: `store` und `destroy` antworten per `RespondsToSettingsForms` (JSON mit `reload`), der neue Schlüssel steht nach dem Neuladen einmal oben (Sitzung `new_token`). Seitenleiste `schluessel-neu`.
  - Webhooks: `store`, `toggle`, `rotateSecret`, `ping`, `destroy` antworten per `RespondsToSettingsForms`; das neue Geheimnis steht nach dem Neuladen einmal oben (Sitzung `new_secret`). Seitenleiste `webhook-neu`.

- [ ] **Step 1: Failing tests**

`tests/Feature/NewsletterPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NewsletterPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_page_shows_url_list_state_and_last_error_never_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'mailwizz', 'status' => 'error',
            'settings' => ['last_error' => 'MailWizz lehnt den API-Key ab (Status 401). Bitte den API-Key in MailWizz prüfen und neu eintragen.'],
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_url' => 'https://news.example.test/api', 'api_key' => 'mailwizz-geheim', 'list_uid' => 'ab12cd'])),
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('id="mailwizz"', false)
            ->assertSee('value="https://news.example.test/api"', false)
            ->assertSee('value="ab12cd"', false)
            ->assertSee('MailWizz lehnt den API-Key ab (Status 401).')
            ->assertDontSee('mailwizz-geheim');
        $this->assertMatchesRegularExpression('/data-nav-key="newsletter"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_internal_address_is_refused_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => 'http://127.0.0.1/api', 'api_key' => 'k', 'list_uid' => 'x', 'enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_url');
    }
}
```

`tests/Feature/ApiTokensPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ApiTokensPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_page_uses_the_new_pattern(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="schluessel-neu"', false)
            ->assertSee('Noch keine API-Schlüssel')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="api"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_creating_answers_with_json_and_shows_the_key_once(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])
            ->assertOk()
            ->assertJsonPath('reload', true);

        $page = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();
        $page->assertSee('Kasse')->assertSee('data-copy=', false);
        $this->actingAs($owner)->get('/admin/api-tokens')->assertDontSee('Jetzt kopieren');
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_missing_scope_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => []])->assertStatus(422);

        $this->assertSame('Bitte mindestens ein Recht anhaken. Für die meisten Programme reicht „Reservierungen lesen“.', $response->json('errors.scopes.0'));
    }

    public function test_a_plan_without_api_explains_where_to_upgrade(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => false]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/api-tokens')
            ->assertSee('Die Schnittstelle ist in deinem Tarif nicht enthalten.')
            ->assertSee('href="'.route('admin.billing.show').'"', false);
        $response = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])->assertForbidden();
        $this->assertStringContainsString('Abo & Rechnungen', (string) $response->json('message'));
    }
}
```

`tests/Feature/WebhooksPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebhooksPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_page_uses_the_new_pattern(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('id="webhook-neu"', false)
            ->assertSee('Noch keine Webhooks')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="webhooks"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_actions_answer_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = WebhookEndpoint::create(['tenant_id' => $setup['tenant']->id, 'url' => 'https://hooks.example.test/swayy', 'secret' => 'alt', 'events' => ['*'], 'is_active' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk()->assertJsonPath('message', 'Webhook pausiert.');
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/rotate-secret')->assertOk()->assertJsonPath('reload', true);
        $this->actingAs($owner)->deleteJson('/admin/webhooks/'.$endpoint->id)->assertOk()->assertJsonPath('message', 'Webhook gelöscht.');
    }
}
```

Tarifmerkmale kommen aus `Tenant::hasFeature()` (Plan, überschrieben durch `feature_overrides`); `WebhookEndpoint` hat `secret` als `hidden`. Steht in `WebhookAdminTest`/`ApiTokenScopeTest` ein anderer Aufbau für den Betrieb mit Tarif, diesen übernehmen.

- [ ] **Step 2: Tests laufen lassen, sie scheitern**

Run: `PAO_DISABLE=1 php artisan test --filter="NewsletterPageTest|ApiTokensPageTest|WebhooksPageTest"`
Expected: FAIL.

- [ ] **Step 3: Newsletter**

`NewsletterController` übernimmt `updateMailwizz`, `mailwizzFailed` und `mailwizzTestFailure` aus `SettingsController` unverändert in der Sache (`enabled` `nullable|boolean`, Erfolg mit `reload: true`). `show()` übergibt `connection`, `apiUrl`, `listUid`, `hasKey`, `lastError` (aus `settings.last_error`), `statusLabel`. Ansicht `settings/newsletter.blade.php`: Seitenkopf (`crumb` Verbindungen, `scope="tenant"`, Beschreibung „Gäste mit Newsletter-Einwilligung gehen an deine Liste in MailWizz.“), Abschnitt `mailwizz` mit Statuszeile, bei Fehler der gespeicherte Grund als Hinweis (Bernstein, mit `x-status-icon state="warn"`), Formular `data-ajax` (ohne Speicherleiste, Geheimnis) mit `x-field api_url` Typ url, Passwortfeld `api_key`, `x-field list_uid`, `x-checkbox enabled` „Gäste mit Einwilligung übertragen“, Knopf „Speichern und Verbindung testen“. Erklärung links (wo URL, Schlüssel und Listen-UID in MailWizz stehen). Die Sammelseite zeigt den Grund weiter (`MailwizzTargetCheckTest` bleibt grün).

- [ ] **Step 4: API-Schlüssel**

`ApiTokenController`:
- `use RespondsToSettingsForms;`
- `SCOPES` bekommt deutsche Bezeichnungen und je einen Satz: `reservations:read` „Reservierungen lesen“, `reservations:write` „Reservierungen anlegen und ändern“, `guests:read`, `guests:write`, `availability:read` „Freie Zeiten abfragen“, `waitlist:write`, `events:read`, `events:write`, `webhooks:manage`, `reports:read` (Salon-Wortlaut „Termine“ bei Reservierungen).
- `store()`: Meldungen `name.required` „Bitte einen Namen eintragen, etwa das Programm, das den Schlüssel nutzt.“, `name.max` „Der Name darf höchstens :max Zeichen haben.“, `scopes.required`/`scopes.min` „Bitte mindestens ein Recht anhaken. Für die meisten Programme reicht „Reservierungen lesen“.“, `scopes.*.in` „Dieses Recht gibt es nicht. Bitte die Seite neu laden.“; Tarifsperre `abort_unless(…, 403, __('Die Schnittstelle ist in deinem Tarif nicht enthalten. Unter „Abo & Rechnungen“ kannst du den Tarif wechseln.'))`; Erfolg: Sitzung `new_token` und `saved($request, __('API-Schlüssel „:name“ angelegt. Kopiere ihn jetzt; Swayy zeigt ihn nur einmal.', …), reload: true)`.
- `destroy()`: Erfolg `saved($request, __('API-Schlüssel „:name“ widerrufen.'), reload: true)`.

Ansicht `api-tokens/index.blade.php` neu: Seitenkopf (`crumb` Verbindungen, `scope="tenant"`, Aktion „API-Schlüssel anlegen“ `data-drawer-open="schluessel-neu"`, gesperrt ohne Tarif). Ohne Tarif ein Hinweis mit Link auf `admin.billing.show`. Neuer Schlüssel aus der Sitzung: Hinweis „Jetzt kopieren: Swayy zeigt diesen Schlüssel nur einmal.“ mit `<code>` und Kopierknopf `data-copy`. Liste: Name, Kurzzeile (Rechte als deutsche Wörter, Besitzer falls fremd, „zuletzt genutzt vor 3 Tagen“/„noch nie genutzt“), `x-inline-confirm` „API-Schlüssel „‹Name›“ widerrufen? Programme mit diesem Schlüssel verlieren sofort den Zugang.“ Leerzustand „Noch keine API-Schlüssel“ mit einer Aktion. Seitenleiste `schluessel-neu`: `x-field name`, Gruppe `fieldset` „Rechte“ mit `x-checkbox` je Recht (Bezeichnung + Satz als Hilfe), Fußknopf „Schlüssel anlegen“. Abschnitt „So nutzt ein Programm den Schlüssel“ mit dem Beispiel und dem Link auf `/api/v1/openapi.yaml`.

- [ ] **Step 5: Webhooks**

`WebhookController`: `use RespondsToSettingsForms;` alle Aktionen antworten per `saved(…)` (JSON mit `message`, bei neuem Geheimnis `reload: true` und Sitzung `new_secret`), sonst Weiterleitung wie heute (`WebhookAdminTest` bleibt grün). Meldungen der Prüfung mit nächstem Schritt (`url.required`, `url.url` „Bitte eine vollständige Adresse mit https:// eintragen.“, `events.required` „Bitte mindestens ein Ereignis anhaken oder „Alle Ereignisse“ wählen.“, `events.*.in` „Dieses Ereignis gibt es nicht. Bitte die Seite neu laden.“). Tarifsperre mit Satz und Weg wie bei den API-Schlüsseln.

Ansicht `webhooks/index.blade.php` neu: Seitenkopf (`crumb` Verbindungen, Aktion „Webhook anlegen“ `data-drawer-open="webhook-neu"`), Hinweis mit neuem Geheimnis (Kopierknopf, Satz zum Header `X-Gastrobook-Signature`), Liste mit Zeile je Endpunkt: Adresse als Titel, `x-status-icon` mit Wort „aktiv“/„pausiert“/„abgeschaltet nach Fehlern“, Kurzzeile (Ereignisse als deutsche Wörter, Zustellungen, Fehler in Folge), Aktionen als Knöpfe in kleinen `data-ajax`-Formularen („Pausieren“/„Aktivieren“, „Testereignis senden“), Rückfragen `x-inline-confirm` für „Geheimnis neu erzeugen“ („Die Gegenstelle prüft danach mit dem neuen Geheimnis; bis zur Umstellung schlagen Zustellungen fehl.“) und „Löschen“. Leerzustand „Noch keine Webhooks“. Seitenleiste `webhook-neu`: `x-field url`, Gruppe „Ereignisse“ mit „Alle Ereignisse“ und je Ereignis `x-checkbox`. Der Abschnitt zu Wiederholungen bleibt (heute `@php`-Block unten in der Ansicht) und liest weiter `swayy.webhooks.*`.

Ereignisnamen bekommen deutsche Bezeichnungen in einer Konstante `WebhookController::EVENT_LABELS` (etwa `reservation.created` „Neue Reservierung“), angezeigt mit dem technischen Namen als `<code>` daneben.

- [ ] **Step 6: Routen, Navigation, Protokoll**

`routes/web.php`: GET `settings/newsletter` in der Gruppe `tables.manage` mit `integrations.manage`; `settings.mailwizz` zeigt auf `NewsletterController::update`. `config/navigation.php`: `newsletter` → `admin.settings.newsletter.show`, `'can' => ['tables.manage', 'integrations.manage']`, Begriffe mit Ziel `mailwizz`; `api` und `webhooks` mit Begriffen auf `schluessel-neu` bzw. `webhook-neu`. `AuditLog`: neue Aktionen, falls Controller neue loggen; `FIELD_LABELS` für `api_url`, `list_uid`, `url`, `events`, falls sie fehlen.

- [ ] **Step 7: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="NewsletterPageTest|ApiTokensPageTest|WebhooksPageTest|MailwizzTargetCheckTest|NewsletterSyncTest|WebhookAdminTest|WebhookDeliveryTest|ApiTokenScopeTest|NavigationTest|AuditLabelTest"`
Expected: PASS. Danach ganze Suite, Pint, PHPStan, `npm test`, `npm run build`.

- [ ] **Step 8: Commit**

```bash
git add -A app/Http/Controllers resources/views/admin/api-tokens resources/views/admin/webhooks resources/views/admin/settings/newsletter.blade.php routes/web.php config/navigation.php app/Models/AuditLog.php config/swayy.php .env.example tests/Feature/NewsletterPageTest.php tests/Feature/ApiTokensPageTest.php tests/Feature/WebhooksPageTest.php
git commit -m "feat(settings): Newsletter, API-Schlüssel und Webhooks im neuen Seitenmuster

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: Fehlerseiten und „Passwort ändern“

**Files:**
- Create: `resources/views/errors/layout.blade.php`, `resources/views/errors/403.blade.php`, `404.blade.php`, `419.blade.php`, `429.blade.php`, `500.blade.php`, `503.blade.php`, `tests/Feature/ErrorPagesTest.php`, `tests/Feature/AccountPasswordTest.php`
- Modify: `app/Http/Controllers/Admin/AccountController.php`, `resources/views/admin/account/index.blade.php`, `routes/web.php`, `config/swayy.php` (Abschnitt `auth`), `.env.example`, `app/Http/Controllers/Auth/PasswordResetController.php` (Mindestlänge aus der Einstellung), `app/Models/AuditLog.php`
- Test: `tests/Feature/PasswordResetTest.php`, `tests/Feature/PasswordResetValidationTest.php`, `tests/Feature/AdminJsonErrorsTest.php` (falls vorhanden; sonst die Tests, die `AdminJsonErrors` prüfen) (laufen lassen)

**Interfaces:**
- Produces: PUT `admin.account.password` (`/admin/account/password`, nur `auth`); Protokollaktion `account.password_changed`; `config('swayy.auth.password_min_length')` (Vorgabe 8, Bereich 8–64); Fehlerseiten für 403, 404, 419, 429, 500, 503.

- [ ] **Step 1: Failing tests**

`tests/Feature/ErrorPagesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403, 'Dieser Betrieb ist gesperrt. Schreib uns an info@swayy.de, dann klären wir den Grund.'));
        Route::middleware('web')->get('/_test/forbidden-plain', fn () => abort(403));
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('SQLSTATE geheim /var/www/html/app'));
        Route::middleware('web')->get('/_test/busy', fn () => abort(429));
        Route::middleware('web')->get('/_test/maintenance', fn () => abort(503));
    }

    public function test_404_names_what_happened_and_the_way_back(): void
    {
        $this->get('/gibt-es-nicht-'.uniqid())
            ->assertNotFound()
            ->assertSee('Diese Seite gibt es nicht')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('404');
    }

    public function test_403_shows_the_reason_from_abort(): void
    {
        $this->get('/_test/forbidden')
            ->assertForbidden()
            ->assertSee('Dieser Betrieb ist gesperrt. Schreib uns an info@swayy.de, dann klären wir den Grund.');
    }

    public function test_403_without_a_reason_names_the_next_step(): void
    {
        $this->get('/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('Dafür fehlt dir das Recht')
            ->assertSee('Inhaber oder die Administration');
    }

    public function test_500_reveals_nothing_internal(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/_test/boom')->assertStatus(500);

        $response->assertSee('Da ist etwas schiefgegangen')
            ->assertSee('Bitte lade die Seite neu')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www');
    }

    public function test_429_and_503_say_when_to_try_again(): void
    {
        $this->get('/_test/busy')->assertStatus(429)->assertSee('Zu viele Anfragen');
        $this->get('/_test/maintenance')->assertStatus(503)->assertSee('Swayy wird gerade aktualisiert');
    }

    public function test_the_admin_keeps_answering_fetch_requests_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->getJson('/admin/settings/capacity')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }
}
```

419 lässt sich mit `$this->withMiddleware()` und einem POST ohne Token prüfen; ist das im Testaufbau zu umständlich, prüft ein Test die Ansicht direkt (`$this->view('errors.419')` enthält „Die Seite war zu lange offen“ und „Bitte lade die Seite neu“).

`tests/Feature/AccountPasswordTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_account_page_offers_the_password_card(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/account')
            ->assertOk()
            ->assertSee('id="passwort"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'falsch-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertStatus(422);

        $this->assertSame('Das aktuelle Passwort stimmt nicht. Bitte erneut eingeben oder „Passwort vergessen“ nutzen.', $response->json('errors.current_password.0'));
        $this->assertTrue(Hash::check('altes-Passwort-1', $owner->fresh()->password));
    }

    public function test_the_minimum_length_comes_from_the_settings(): void
    {
        config(['swayy.auth.password_min_length' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'kurz-Pass-1', 'password_confirmation' => 'kurz-Pass-1',
        ])->assertStatus(422);

        $this->assertStringContainsString('12', $response->json('errors.password.0'));
    }

    public function test_a_new_password_works_and_is_logged_without_the_password(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertOk()->assertJsonPath('message', 'Passwort geändert.');

        $this->assertTrue(Hash::check('neues-Passwort-2', $owner->fresh()->password));
        $log = AuditLog::where('action', 'account.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('neues-Passwort-2', json_encode([$log->old_values, $log->new_values]));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, sie scheitern**

Run: `PAO_DISABLE=1 php artisan test --filter="ErrorPagesTest|AccountPasswordTest"`
Expected: FAIL.

- [ ] **Step 3: Fehlerseiten**

`resources/views/errors/layout.blade.php`: eigenständige Seite ohne Admin-Layout (Fehler können vor der Anmeldung oder ohne Betrieb entstehen), mit `@vite(['resources/css/app.css'])` wie `resources/views/errors/license_expired.blade.php`, `<title>` „‹Titel› – Swayy“, Logo/Wortmarke Swayy, `<main>` mit `<h1>` (Titel), einem Absatz Ursache, einem Absatz nächster Schritt, Knöpfen (je Seite) und dem Statuscode klein darunter („Fehlercode 404“). Keine Ausgabe von `$exception` außer der Meldung bei 403 und 503, und dort nur, wenn sie nicht leer ist. Kontrast und Fokus wie im Admin.

| Code | Titel | Ursache | Nächster Schritt, Knöpfe |
|---|---|---|---|
| 403 | Dafür fehlt dir das Recht | Meldung aus `abort()` oder „Diese Seite ist deiner Rolle nicht freigegeben.“ | „Frag den Inhaber oder die Administration deines Betriebs.“ Knöpfe „Zur Übersicht“ (`/admin`, wenn angemeldet, sonst `/`) |
| 404 | Diese Seite gibt es nicht | „Die Adresse ist falsch oder der Eintrag wurde gelöscht.“ | Knöpfe „Zur Startseite“ (`url('/')`), angemeldet zusätzlich „Zur Übersicht“ |
| 419 | Die Seite war zu lange offen | „Aus Sicherheitsgründen gilt ein Formular nur eine Weile.“ | „Bitte lade die Seite neu und sende das Formular noch einmal.“ Knopf „Seite neu laden“ (Link auf die vorige Adresse, sonst `/`) |
| 429 | Zu viele Anfragen | „In kurzer Zeit kamen sehr viele Anfragen von deinem Anschluss.“ | „Bitte warte einen Moment und versuch es dann erneut.“ Steht `Retry-After` in der Antwort, die Wartezeit nennen |
| 500 | Da ist etwas schiefgegangen | „Swayy konnte die Anfrage gerade nicht ausführen. Der Fehler ist protokolliert.“ | „Bitte lade die Seite neu. Tritt der Fehler wieder auf, schreib uns an ‹Supportadresse›.“ Supportadresse aus der neuen Einstellung `swayy.support.email` (`SWAYY_SUPPORT_EMAIL`, Vorgabe `info@swayy.de`, Prüfung als E-Mail-Adresse; `.env.example` dazu) |
| 503 | Swayy wird gerade aktualisiert | Meldung aus `abort()` oder „Gleich ist alles wieder da.“ | „Bitte versuch es in ein paar Minuten noch einmal.“ |

Die Verwaltung beantwortet `fetch`-Anfragen weiter mit JSON über `AdminJsonErrors`; daran ändert dieser Task nichts.

- [ ] **Step 4: Passwort ändern**

`config/swayy.php`, Abschnitt `auth` (gibt es schon einen, dort ergänzen): `password_min_length` (`SWAYY_PASSWORD_MIN_LENGTH`, Vorgabe 8, Bereich 8–64), Kommentar „Mindestlänge für neue Passwörter (Zurücksetzen und „Mein Konto“); zusätzlich Buchstaben und Ziffern“. `.env.example` dazu. `PasswordResetController::reset` nutzt `PasswordRule::min((int) config('swayy.auth.password_min_length'))->letters()->numbers()`.

`AccountController::updatePassword(Request $request)`:

```php
    public function updatePassword(Request $request): mixed
    {
        $min = (int) config('swayy.auth.password_min_length');
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min($min)->letters()->numbers()],
        ], [
            'current_password.required' => __('Bitte dein aktuelles Passwort eingeben.'),
            'current_password.current_password' => __('Das aktuelle Passwort stimmt nicht. Bitte erneut eingeben oder „Passwort vergessen“ nutzen.'),
            'password.required' => __('Bitte ein neues Passwort eingeben.'),
            'password.confirmed' => __('Die Wiederholung passt nicht zum neuen Passwort. Bitte beide Felder gleich ausfüllen.'),
            'password.min' => __('Das neue Passwort braucht mindestens :min Zeichen. Bitte ein längeres wählen.'),
            'password.letters' => __('Das neue Passwort braucht mindestens einen Buchstaben.'),
            'password.numbers' => __('Das neue Passwort braucht mindestens eine Ziffer.'),
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();
        $request->session()->regenerate();

        $this->audit->log('account.password_changed', $user, null, null, null, $user, $this->context->tenantId());

        return $this->saved($request, __('Passwort geändert.'));
    }
```

(`use RespondsToSettingsForms;`, `use Illuminate\Support\Facades\Hash;`, `use Illuminate\Validation\Rules\Password as PasswordRule;`. Die genaue Signatur von `AuditLogger::log` prüfen und die Argumente anpassen; ohne Betrieb loggt der Aufruf mit `tenant_id` null, wenn der Logger das erlaubt, sonst nur mit Betrieb.)

Route in der Gruppe der Kontorouten (ohne `permission`-Middleware): `Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:6,1')->name('account.password');` Die Drosselung kommt aus einer Einstellung (`swayy.auth.password_change_per_minute`, Vorgabe 6, Bereich 3–30), gelesen über einen benannten Limiter in `AppServiceProvider` (Muster der vorhandenen Limiter dort nachsehen).

`account/index.blade.php`: Abschnitt `passwort` „Passwort“ zwischen Profil und „Konto löschen“: Formular `data-ajax data-reset-after-save` PUT `admin.account.password`, drei `x-field` vom Typ password (`current_password` mit `autocomplete="current-password"`, `password` und `password_confirmation` mit `autocomplete="new-password"`), Hilfe „Mindestens :min Zeichen, mit Buchstaben und Ziffern.“, Knopf „Passwort ändern“. Keine Speicherleiste (Passwortfelder).

`AuditLog::ACTION_LABELS`: `'account.password_changed' => 'Passwort geändert'`.

- [ ] **Step 5: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="ErrorPagesTest|AccountPasswordTest|PasswordResetTest|PasswordResetValidationTest|AuditLabelTest|AccountPage"`
Expected: PASS. Danach ganze Suite, Pint, PHPStan, `npm run build`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/errors app/Http/Controllers/Admin/AccountController.php resources/views/admin/account/index.blade.php routes/web.php config/swayy.php .env.example app/Http/Controllers/Auth/PasswordResetController.php app/Models/AuditLog.php app/Providers/AppServiceProvider.php tests/Feature/ErrorPagesTest.php tests/Feature/AccountPasswordTest.php
git commit -m "feat: Eigene Fehlerseiten mit nächstem Schritt, Passwort ändern unter „Mein Konto“

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: Restpunkte Protokoll

**Files:**
- Modify: `app/Http/Controllers/Admin/AuditLogController.php`, `app/Models/AuditLog.php`, `resources/views/admin/audit/index.blade.php`, `config/swayy.php` (falls neue Grenzen, etwa Länge der Suche)
- Test: `tests/Feature/AuditLogFilterTest.php`, `tests/Feature/AuditFieldLabelTest.php`, `tests/Feature/AuditLabelTest.php`, `tests/Feature/AuditLogDiffTest.php`

**Interfaces:**
- Produces: Filter `q` (Freitext) im Protokoll; `AuditLog::SUBJECTS` um die Präfixe aller geloggten Aktionen ergänzt; Werte zu `type` und den Feldregeln lesbar; Feldbezeichnungen je Aktion.

- [ ] **Step 1: Failing tests (in die bestehenden Testklassen)**

```php
    public function test_every_logged_action_prefix_has_an_area(): void
    {
        // Alle Präfixe, die der Code loggt: app/ nach ->log('präfix. durchsuchen.
        $prefixes = collect(File::allFiles(app_path()))
            ->flatMap(fn ($file) => preg_match_all("/->log\\('([a-z_]+)\\./", $file->getContents(), $m) ? $m[1] : [])
            ->unique()->values();

        foreach ($prefixes as $prefix) {
            $this->assertArrayHasKey($prefix, AuditLog::SUBJECTS, "Bereich für „{$prefix}“ fehlt in AuditLog::SUBJECTS.");
        }
    }

    public function test_free_text_finds_entries_by_label_and_values(): void
    {
        // Zwei Einträge: einer mit „Tisch 7“ in new_values, einer ohne. Suche „Tisch 7“ findet genau einen,
        // auf SQLite und PostgreSQL (JSON als Text durchsuchen, ohne Groß-/Kleinschreibung).
    }

    public function test_type_values_and_field_rules_read_as_words(): void
    {
        // tenant.type_updated mit type restaurant → salon zeigt „Restaurant“ → „Salon“;
        // location.field_rules_updated mit note hidden → optional zeigt „Anmerkung“: „Ausgeblendet“ → „Freiwillig“.
    }
```

Die drei Tests in der Form der jeweiligen Testklasse ausschreiben (Daten anlegen, Seite oder `fieldChanges()` abrufen, Zusicherungen auf die genannten Wörter). Für die Freitextsuche einen Test mit einem Wert außerhalb der Vorgabe der Suchlänge (siehe Step 2).

- [ ] **Step 2: Umsetzen**

- `AuditLog::SUBJECTS` um alle geloggten Präfixe ergänzen (der Test zeigt, welche fehlen; etwa `auth` „Anmeldung“, `support` „Support“, `provisioning` „Kundenpanel“), deutsch und zur Oberfläche passend.
- `AuditLog::labelFor()`: Fehlt eine Bezeichnung, zeigt die Fallback-Zeile den Bereich und „Aktion ‹technischer Name›“ in `<code>`; Englisch aus `Str::headline` entfällt.
- `AuditLog::formatValue()`: `type` mit Werten `restaurant`/`salon` → „Restaurant“/„Salon“; Feldregeln (`hidden`, `optional`, `required`) bei Aktion `location.field_rules_updated` → „Ausgeblendet“/„Freiwillig“/„Pflichtfeld“. Weil `formatValue` heute statisch ohne Aktion arbeitet, bekommt `fieldChanges()` die Aktion mit und reicht sie weiter; Feldbezeichnungen je Aktion über eine neue Konstante `ACTION_FIELD_LABELS` (`'location.field_rules_updated' => ['note' => 'Anmerkung', 'occasion' => 'Anlass', 'allergies' => 'Allergien', 'email' => 'E-Mail', 'phone' => 'Telefon']`).
- Fehlende Feldbezeichnungen der 2b-Seiten (Kapazität, Buchungsregeln, Buchungsseite, Räume, Leistungen, Mitarbeiter) in `FIELD_LABELS` ergänzen; `AuditFieldLabelTest` erweitert um eine Prüfung, dass jedes Feld, das ein Controller dieser Seiten protokolliert, eine Bezeichnung hat (Feldlisten aus den `FIELDS`-Konstanten bzw. Prüfregeln der Controller).
- `AuditLogController`: Filter `q` (Text, getrimmt, höchstens `config('swayy.audit.search_max')` Zeichen, Vorgabe 100, Bereich 20–200), sucht ohne Groß-/Kleinschreibung in `action`, in den Bezeichnungen (Aktionen, deren deutsche Bezeichnung den Text enthält, als `whereIn('action', …)`) und in `old_values`/`new_values` als Text (`CAST(… AS TEXT)` unter PostgreSQL, unter SQLite direkt). `filtered` berücksichtigt `q`.
- Ansicht: Suchfeld „Suchen“ im Filterbereich (Beschriftung über dem Feld, `x-field`), Filter „Bereich“ mit den neuen Bereichen, Leerzustand nennt den Suchbegriff und bietet „Filter zurücksetzen“.

- [ ] **Step 3: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter="AuditLogFilterTest|AuditFieldLabelTest|AuditLabelTest|AuditLogDiffTest|BookingFormPageTest"`, dazu PostgreSQL-Lauf für `AuditLogFilterTest`.
Expected: PASS. Danach ganze Suite, Pint, PHPStan.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/AuditLogController.php app/Models/AuditLog.php resources/views/admin/audit/index.blade.php config/swayy.php .env.example tests/Feature/AuditLogFilterTest.php tests/Feature/AuditFieldLabelTest.php tests/Feature/AuditLabelTest.php tests/Feature/AuditLogDiffTest.php
git commit -m "feat(admin): Protokoll mit Freitextsuche, allen Bereichen und lesbaren Werten

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Prüfung Teil 2c, Protokoll

Führt die steuernde Sitzung selbst aus.

- [ ] **Step 1:** Ganze Suite SQLite, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm test`, `npm run build`; Zahlen mit 2b vergleichen (1529 PHPUnit nach dem Abgleich, 111 Vitest).
- [ ] **Step 2:** PostgreSQL-Lauf mit Container `gastrobook-pgtest-2c` auf Port 55433.
- [ ] **Step 3:** Browserprüfung lokal (127.0.0.1:8010, Inhaber aus dem Seeder) bei 390, 768 und 1280 px ohne seitliches Scrollen: Benachrichtigungen (Anrede wechseln, Erinnerung aus/ein mit Einblenden, zu großer Stundenwert → Text am Feld, Link „Text bearbeiten“ öffnet die Seitenleiste der Vorlage), Vorlagen (bearbeiten, zurücksetzen per Rückfrage), Absender & Antworten (speichern, Weiterleitung ohne Domain gesperrt), SMS-Versand (Seite ohne Schlüssel im HTML), Zahlungsanbieter (Webhook-Adresse kopieren), Anzahlungen (Regel anlegen mit 12.50, bearbeiten, löschen; Erstattung über die Speicherleiste), Newsletter, API-Schlüssel (anlegen, einmalige Anzeige, widerrufen), Webhooks (anlegen mit Testadresse, pausieren, löschen), Fehlerseiten 404 und 403, „Mein Konto“ Passwort (falsches aktuelles Passwort → Text am Feld; das Passwort des Seeder-Kontos danach wieder auf den Ausgangswert setzen), Protokoll (Suche, Bereich). Testdaten danach entfernen.
- [ ] **Step 4:** Befunde beheben (je Befund ein Commit mit Test), Step 1 erneut.
- [ ] **Step 5:** Prüfprotokoll `docs/superpowers/plans/2026-10-11-new-ui-teil-2c-protokoll.md` (Stand, Ergebnisse, Browserprüfung, Gesamtprüfung und Korrekturen, Entscheidungen, offen für Teil 3), Commit „docs: Prüfprotokoll Teil 2c“.

## Offen für Teil 3

| Punkt | Wohin |
|---|---|
| Gruppe `permission:tables.manage` um alle Einstellungsrouten auflösen, jede Seite nur mit dem Recht ihrer Karten | Teil 3 (Abbau der Sammelseite) |
| Sammelseite, `SettingsController::index`, `updateBookingRules` und die alten Karten | Teil 3 |
| Handbuch der Seiten aus 2c | Teil 3 (Handbuch) |

## Ausführung

| Rolle | Anzahl | Aufgabe | Dateien | Modell |
|---|---|---|---|---|
| Umsetzer | 6 (je Task 1–6 einer) | Task nach Plan umsetzen, Tests laufen lassen, committen | die Dateien unter „Files“ des Tasks | Sonnet 5.5 |
| Prüfer | 6 (je Task einer) | Diff des Tasks gegen Plan, Spec und Global Constraints prüfen | lesend: Diff, Plan-Auszug, Spec | Sonnet 5.5 |
| Gesamtprüfer | 1 | ganzer Zweig nach Task 6 | lesend: `git diff` seit aabd562 | Sonnet 5.5 |

Korrekturen gehen per Nachricht an denselben Umsetzer bzw. Prüfer. Jeder Neustart eines Agenten braucht eine neue Freigabe.

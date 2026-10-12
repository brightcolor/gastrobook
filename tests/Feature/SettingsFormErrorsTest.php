<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DepositRule;
use App\Models\IntegrationConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Einstellungsseite schickt ihre Formulare per fetch und zeigt, was als
 * JSON zurueckkommt. Jede Ablehnung muss deshalb als JSON mit einer deutschen
 * Meldung ankommen, die Grund und naechsten Schritt nennt. Einer Weiterleitung
 * folgte fetch still, und die Seite meldete "Gespeichert".
 */
class SettingsFormErrorsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Die Ausnahmen verlangen Tage ab heute; die Beispiele liegen im Dezember 2026.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /**
     * Jedes Formular der Seite mit einer Eingabe, die abgelehnt wird.
     *
     * {room}, {table} und {rule} stehen fuer Eintraege des eigenen Standorts.
     *
     * @return array<string, array{string, string, array<string, mixed>, string, string}>
     */
    public static function rejectedForms(): array
    {
        return [
            'Stammdaten' => ['PUT', '/admin/settings/general',
                ['business_name' => ''],
                'business_name', 'Bitte den Namen des Betriebs eintragen.'],
            'Betriebstyp' => ['PUT', '/admin/settings/tenant-type',
                ['type' => 'hotel'],
                'type', 'Der gewählte Wert für Betriebstyp ist ungültig.'],
            'Markenfarbe' => ['PUT', '/admin/settings/branding',
                ['brand_primary_color' => 'rot'],
                'brand_primary_color', 'Die Farbe muss im Format #rrggbb angegeben sein, etwa #0f766e. Bitte über das Farbfeld auswählen.'],
            'Formularfelder' => ['PUT', '/admin/settings/field-rules',
                ['fields' => ['email' => 'immer', 'phone' => 'optional', 'occasion' => 'optional', 'note' => 'optional', 'allergies' => 'optional']],
                'fields.email', 'Bitte für E-Mail „Ausgeblendet“, „Freiwillig“ oder „Pflichtfeld“ wählen.'],
            'Kapazität & Dauer' => ['PUT', '/admin/settings/capacity',
                self::capacity(['default_duration_minutes' => 10]),
                'default_duration_minutes', 'Standarddauer muss mindestens 30 sein.'],
            'Kapazität mit unbekanntem Takt' => ['PUT', '/admin/settings/capacity',
                self::capacity(['slot_interval_minutes' => 7]),
                'slot_interval_minutes', 'Der Wert bei „Takt der Uhrzeiten“ steht nicht zur Auswahl. Bitte einen Wert aus der Liste wählen oder die Seite neu laden.'],
            'Kapazität ohne Zählweise' => ['PUT', '/admin/settings/capacity',
                self::capacity(['capacity_mode' => '']),
                'capacity_mode', 'Bei „Zählweise“ fehlt ein Wert. Bitte einen Wert eintragen oder wählen; steht das Feld nicht auf der Seite, die Seite neu laden.'],
            'Buchungsregeln mit unklarem Schalter' => ['PUT', '/admin/settings/rules',
                self::bookingRules(['waitlist_enabled' => 'vielleicht']),
                'waitlist_enabled', 'Bitte „Warteliste anbieten“ ein- oder ausschalten.'],
            'Öffnungszeiten' => ['PUT', '/admin/settings/opening-hours',
                ['hours' => [['weekday' => 1, 'opens_at' => '25:00', 'closes_at' => '22:00']]],
                'hours.0.opens_at', 'Bitte die Uhrzeit als HH:MM eintragen, etwa 11:30.'],
            'Öffnungszeiten ohne Angabe der Woche' => ['PUT', '/admin/settings/opening-hours',
                [],
                'hours', 'Die Öffnungszeiten fehlen in der Anfrage. Bitte die Seite neu laden und noch einmal speichern.'],
            'Öffnungszeiten überschneiden sich' => ['PUT', '/admin/settings/opening-hours',
                ['hours' => [['weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00'], ['weekday' => 1, 'opens_at' => '14:00', 'closes_at' => '22:00']]],
                'hours.1.opens_at', 'Dieses Zeitfenster überschneidet sich mit 11:00–15:00 am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.'],
            'Saison mit unmöglichem Monat' => ['POST', '/admin/settings/seasons',
                ['start_month' => 13, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31],
                'start_month', 'Bitte einen Monat aus der Liste wählen.'],
            // Weitere Ablehnungen der Ausnahmen (Zeitfenster, Gästegrenze, Sperre, Tische und Räume anderer Standorte) prüft ScheduleExceptionsTest.
            'Ausnahme' => ['POST', '/admin/settings/exceptions',
                ['from' => '2026-12-24', 'until' => '2026-12-23', 'effects' => ['closed']],
                'until', 'Das Ende liegt vor dem Beginn. Bitte ein späteres Datum wählen.'],
            'Ausnahme mit Sperre ohne zweite Uhrzeit' => ['POST', '/admin/settings/exceptions',
                ['from' => '2026-12-24', 'effects' => ['lock'], 'lock_tables' => ['{table}'], 'lock_from' => '18:00'],
                'lock_until', 'Bitte beide Uhrzeiten eintragen oder beide leer lassen.'],
            'Raum anlegen' => ['POST', '/admin/settings/rooms',
                ['name' => ''],
                'name', 'Bitte einen Namen eintragen.'],
            'Raum umbenennen' => ['PUT', '/admin/settings/rooms/{room}',
                ['name' => ''],
                'name', 'Bitte einen Namen eintragen.'],
            'Raumgröße' => ['PATCH', '/admin/floorplan/rooms/{room}/size',
                ['plan_width_m' => 0.5],
                'plan_width_m', 'Die Breite muss mindestens 1 Meter betragen. Bitte eine größere Zahl eintragen.'],
            'Tisch bearbeiten' => ['PUT', '/admin/settings/tables/{table}',
                ['name' => 'T1', 'min_capacity' => 4, 'max_capacity' => 2],
                'max_capacity', 'Die größte Personenzahl ist kleiner als die kleinste. Bitte mindestens 4 eintragen.'],
            'Tag anlegen' => ['POST', '/admin/tags',
                ['name' => '', 'color' => '#aa3300'],
                'name', 'Bitte gib dem Tag einen Namen.'],
            'Stripe ohne Secret Key' => ['PUT', '/admin/settings/stripe',
                ['webhook_secret' => 'whsec_test', 'enabled' => '1'],
                'secret_key', 'Der Secret Key fehlt. Bitte ihn aus dem Stripe-Dashboard unter Developers → API keys eintragen.'],
            'Stripe ohne Signing-Secret' => ['PUT', '/admin/settings/stripe',
                ['secret_key' => 'sk_test_123', 'enabled' => '1'],
                'webhook_secret', 'Das Webhook-Signing-Secret fehlt. Bitte es im Stripe-Dashboard beim Webhook-Endpunkt abrufen und eintragen.'],
            'PayPal ohne Client-ID' => ['PUT', '/admin/settings/paypal',
                ['secret' => 'paypal-secret', 'mode' => 'live', 'enabled' => '1'],
                'client_id', 'Die Client-ID fehlt. Bitte sie aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.'],
            'PayPal ohne Secret' => ['PUT', '/admin/settings/paypal',
                ['client_id' => 'paypal-client', 'mode' => 'live', 'enabled' => '1'],
                'secret', 'Das Secret fehlt. Bitte es aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.'],
            'Anzahlungsregel anlegen' => ['POST', '/admin/settings/deposit-rules',
                ['name' => 'Gruppen ab 6'],
                'amount_per_person', 'Bitte den Betrag pro Person in Euro eintragen, etwa 12.50. Bei einem reinen Grundbetrag steht hier 0.'],
            'Anzahlungsregel ändern' => ['PUT', '/admin/settings/deposit-rules/{rule}',
                ['name' => 'Gruppen ab 6', 'amount_per_person' => -5],
                'amount_per_person', 'Bitte den Betrag in Euro mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 12.50.'],
            'MailWizz' => ['PUT', '/admin/settings/mailwizz',
                ['api_url' => '', 'list_uid' => 'ab12cd34'],
                'api_url', 'Die API-URL fehlt. Bitte die Adresse deiner MailWizz-API eintragen, etwa https://news.example.com/api (in MailWizz unter Account → API).'],
            'SMS' => ['PUT', '/admin/settings/sms',
                ['enabled' => '1'],
                'api_key', 'Der API-Key fehlt. Bitte den API-Key aus seven.io eintragen (app.seven.io → Einstellungen → API).'],
        ];
    }

    #[DataProvider('rejectedForms')]
    public function test_a_rejected_form_answers_with_json_and_the_reason(
        string $method, string $uri, array $payload, string $field, string $message,
    ): void {
        $admin = $this->adminWithEntries($ids);

        $response = $this->actingAs($admin)
            ->json($method, strtr($uri, $ids), $this->fill($payload, $ids))
            ->assertStatus(422);

        // Feldnamen wie "hours.0.opens_at" enthalten Punkte, deshalb ohne
        // assertJsonPath.
        $this->assertSame($message, $response->json('errors')[$field][0] ?? null, (string) $response->getContent());
    }

    /**
     * Ohne fetch, etwa mit abgeschaltetem JavaScript, bleibt alles beim Alten:
     * zurueck zur Seite, die Meldung steht in der Sitzung.
     */
    public function test_a_classic_form_post_still_goes_back_with_the_errors(): void
    {
        $admin = $this->adminWithEntries($ids);

        $this->actingAs($admin)
            ->from('/admin/settings/capacity')
            ->put('/admin/settings/capacity', self::capacity(['default_duration_minutes' => 10]))
            ->assertRedirect('/admin/settings/capacity')
            ->assertSessionHasErrors(['default_duration_minutes' => 'Standarddauer muss mindestens 30 sein.']);
    }

    /**
     * Das Onboarding legt Tische ueber denselben Weg an, ebenfalls per fetch.
     */
    public function test_a_new_table_is_confirmed_as_json(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertOk()->assertExactJson(['message' => 'Tisch angelegt.']);
    }

    public function test_a_table_beyond_the_plan_limit_says_how_to_get_more(): void
    {
        $setup = $this->createTenantSetup();
        $plan = $setup['tenant']->plan;
        $plan->update(['limits' => array_merge($plan->limits, ['max_tables' => count($setup['tables'])])]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422);

        $this->assertSame(
            'Dein Tarif umfasst 3 Tische, und alle sind schon angelegt. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.',
            $response->json('errors.name.0')
        );
    }

    public function test_a_table_in_a_room_of_another_location_is_refused_with_a_reason(): void
    {
        $admin = $this->adminWithEntries($ids);
        $foreign = $this->createTenantSetup();

        $response = $this->actingAs($admin)->postJson('/admin/settings/tables', [
            'room_id' => $foreign['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422);

        $this->assertSame(
            'Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.',
            $response->json('errors.room_id.0')
        );
    }

    /**
     * Der Verbindungstest laeuft nach dem Speichern. Scheitert er, ist die
     * Anbindung gespeichert und steht auf "Fehler"; die Meldung sagt beides.
     */
    public function test_a_failed_sms_connection_test_says_what_was_saved_and_what_to_do(): void
    {
        $admin = $this->adminWithEntries($ids);

        Http::fake(['*' => Http::response('Unauthorized', 401)]);
        $this->actingAs($admin)->putJson('/admin/settings/sms', ['api_key' => 'falscher-key', 'enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.api_key.0', 'Gespeichert, aber seven.io hat den Verbindungstest abgelehnt. Bitte den API-Key unter app.seven.io → Einstellungen → API prüfen und neu eintragen.');

        Http::fake(['*' => Http::failedConnection()]);
        $this->actingAs($admin)->putJson('/admin/settings/sms', ['api_key' => 'test-key', 'enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.api_key.0', 'Gespeichert, aber seven.io war beim Verbindungstest nicht erreichbar. Bitte später noch einmal auf „Speichern“ klicken, um die Verbindung zu testen.');

        $this->assertSame('error', IntegrationConnection::withoutGlobalScopes()->where('provider', 'sevenio')->value('status'));
    }

    public function test_the_summary_of_several_errors_reads_german(): void
    {
        $admin = $this->adminWithEntries($ids);

        $this->actingAs($admin)
            ->putJson('/admin/settings/capacity', self::capacity(['default_duration_minutes' => 10, 'buffer_minutes' => 500]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Standarddauer muss mindestens 30 sein. (und 1 weiterer Fehler)');
    }

    public function test_a_missing_permission_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)
            ->putJson('/admin/settings/tenant-type', ['type' => 'salon'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Für diese Aktion fehlt die Berechtigung (tenant.settings.manage). Bitte einen Administrator des Betriebs fragen.']);
    }

    public function test_a_vanished_entry_is_reported_without_internals(): void
    {
        $admin = $this->adminWithEntries($ids);

        $this->actingAs($admin)
            ->putJson('/admin/settings/rooms/999999', ['name' => 'Terrasse'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Der Eintrag wurde nicht gefunden. Vielleicht wurde er inzwischen gelöscht. Bitte die Seite neu laden.']);
    }

    public function test_an_expired_login_asks_to_sign_in_again(): void
    {
        $this->createTenantSetup();

        $this->putJson('/admin/settings/rules', self::bookingRules())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Die Anmeldung ist abgelaufen. Bitte neu anmelden und es dann noch einmal versuchen.']);
    }

    public function test_an_expired_session_asks_to_reload_the_page(): void
    {
        Route::put('/admin/probe/sitzung', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->putJson('/admin/probe/sitzung')
            ->assertStatus(419)
            ->assertExactJson(['message' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und es dann noch einmal versuchen.']);
    }

    public function test_too_many_requests_say_when_to_try_again(): void
    {
        Route::put('/admin/probe/bremse', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 42]));

        $this->putJson('/admin/probe/bremse')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '42')
            ->assertExactJson(['message' => 'Zu viele Anfragen in kurzer Zeit. Bitte in 42 Sekunden noch einmal versuchen.']);
    }

    public function test_a_too_large_upload_says_what_to_do(): void
    {
        Route::post('/admin/probe/upload', fn () => throw new PostTooLargeException);

        $this->postJson('/admin/probe/upload')
            ->assertStatus(413)
            ->assertExactJson(['message' => 'Die Datei ist zu groß. Bitte eine kleinere Datei wählen.']);
    }

    /**
     * Einzelheiten eines unerwarteten Fehlers gehoeren ins Log. Die Antwort
     * sagt, was jetzt zu tun ist.
     */
    public function test_an_unexpected_error_keeps_the_details_out_of_the_answer(): void
    {
        config(['app.debug' => false]);
        Route::put('/admin/probe/absturz', fn () => throw new RuntimeException('Interna aus /var/www/html/app'));

        $this->putJson('/admin/probe/absturz')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Auf dem Server ist ein Fehler aufgetreten. Bitte die Seite neu laden und es noch einmal versuchen. Tritt der Fehler wieder auf, bitte den Betreiber informieren und die Uhrzeit nennen.']);
    }

    /**
     * Eine eigene Meldung aus abort() kommt unveraendert an.
     */
    public function test_a_reason_given_by_the_application_is_kept(): void
    {
        Route::put('/admin/probe/eigener-grund', fn () => abort(409, 'Diese Reservierung wurde gerade von jemand anderem geändert. Bitte die Seite neu laden.'));

        $this->putJson('/admin/probe/eigener-grund')
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Diese Reservierung wurde gerade von jemand anderem geändert. Bitte die Seite neu laden.']);
    }

    /**
     * Antworten ohne JSON (Fehlerseite eines Proxys, abgebrochene Verbindung)
     * uebersetzt das Skript der Seite selbst. Die Texte bekommt es vom Server.
     */
    public function test_the_page_script_knows_what_to_say_without_a_server_reason(): void
    {
        $admin = $this->adminWithEntries($ids);

        $this->actingAs($admin)->get('/admin/settings/general')
            ->assertOk()
            ->assertSee('Keine Verbindung zum Server', false)
            ->assertSee('Die Sitzung ist abgelaufen', false)
            ->assertSee('Der Server antwortet gerade nicht', false);
    }

    /**
     * Gueltige Angaben der Seite Kapazität & Dauer; $overrides macht einzelne Felder ungueltig.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function capacity(array $overrides = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 30,
            'default_duration_minutes' => 120,
            'buffer_minutes' => 0,
            'capacity_mode' => 'table',
            'max_covers_per_slot' => '',
        ], $overrides);
    }

    /**
     * Gueltige Angaben der Seite Buchungsregeln; $overrides macht einzelne Felder ungueltig.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function bookingRules(array $overrides = []): array
    {
        return array_merge([
            'min_lead_minutes' => 0,
            'max_advance_days' => 90,
            'min_party_online' => 1,
            'max_party_online' => 10,
            'large_group_email' => '',
            'cancellation_deadline_minutes' => 120,
            'modification_deadline_minutes' => 120,
            'booking_confirmation_mode' => 'auto',
            'require_email_confirmation' => '0',
            'waitlist_enabled' => '1',
            'walkins_enabled' => '1',
        ], $overrides);
    }

    /**
     * Betrieb mit Administrator, Raum, Tisch und Anzahlungsregel.
     *
     * @param-out array<string, string> $ids  Platzhalter => ID
     */
    private function adminWithEntries(?array &$ids): User
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $rule = DepositRule::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Gruppen ab 6',
            'type' => 'deposit',
            'amount_per_person_minor' => 1000,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'is_active' => true,
        ]);

        $ids = [
            '{room}' => (string) $setup['room']->id,
            '{table}' => (string) $setup['tables'][0]->id,
            '{rule}' => (string) $rule->id,
        ];

        $this->clearTenantContext();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $ids
     * @return array<string, mixed>
     */
    private function fill(array $payload, array $ids): array
    {
        array_walk_recursive($payload, function (mixed &$value) use ($ids) {
            if (is_string($value)) {
                $value = strtr($value, $ids);
            }
        });

        return $payload;
    }
}

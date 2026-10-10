<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Http\Controllers\Admin\ServiceController;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ServicesPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['owner'] = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['staff'] = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Anna', 'is_active' => true,
        ]);

        return $setup;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $setup, string $name = 'Schnitt', array $attributes = []): Service
    {
        return Service::create($attributes + [
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => $name,
            'duration_minutes' => 60, 'price_minor' => 3500, 'currency' => 'EUR', 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function appointment(array $setup, Service $service, ReservationStatus $status = ReservationStatus::Confirmed, int $days = 1, bool $viaPivot = false): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays($days)->setTime(10, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHour()->utc(),
            'timezone' => $setup['location']->timezone, 'status' => $status, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast', 'service_id' => $viaPivot ? null : $service->id,
        ]);
        if ($viaPivot) {
            $reservation->services()->attach($service->id, ['sort_order' => 0, 'duration_minutes' => 60, 'price_minor' => 3500]);
        }

        return $reservation;
    }

    /** Inhalt der Seite; die Navigation führt Einträge beider Betriebstypen auf. */
    private function mainContent(string $html): string
    {
        $this->assertSame(1, preg_match('/<main.*?<\/main>/s', $html, $main));

        return $main[0];
    }

    /** Seitenleisten der Seite: Sie stehen hinter dem Hauptbereich. */
    private function drawers(string $html): string
    {
        $start = strpos($html, 'id="leistung-neu" data-drawer');
        $end = strpos($html, 'id="swayyToast"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** Seitenleiste „Neue Leistung“: vom Anfang bis zum Ende ihres Formulars. */
    private function newDrawer(string $html): string
    {
        $drawers = $this->drawers($html);

        return substr($drawers, 0, (int) strpos($drawers, '</form>'));
    }

    // ── Aufbau der Seite ─────────────────────────────────────────────────

    public function test_salon_owner_sees_services_with_drawers(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $service->staff()->attach($setup['staff']->id);
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->get('/admin/services')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-drawer-open="leistung-'.$service->id.'"', false)
            ->assertSee('id="leistung-neu" data-drawer hidden', false)
            ->assertSee('id="leistung-'.$service->id.'" data-drawer hidden', false)
            ->assertSee('1 Std. · 35,00 € · Anna')
            ->assertSee('<span class="sr-only">Online buchbar</span>', false)
            ->assertSee('name="price"', false)
            ->assertSee('value="35.00"', false)
            ->assertSee('Leistung „Schnitt“ löschen? Gäste können sie dann nicht mehr buchen.');
        $this->assertMatchesRegularExpression('/data-nav-key="services"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_list_offers_the_first_service_and_names_the_missing_team(): void
    {
        $setup = $this->salon();
        $setup['staff']->update(['is_active' => false]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/services')
            ->assertOk()
            ->assertSee('Noch keine Leistungen')
            ->assertSee('Buchbar wird eine Leistung, sobald eine Person sie anbietet.')
            ->assertSee('Personen legst du unter')
            ->assertDontSee('<ul class="divide-y', false)
            ->getContent();

        // Der Leerzustand trägt die Aktion; der Knopf im Seitenkopf steht davor.
        $this->assertMatchesRegularExpression('/Noch keine Leistungen<\/p>.*?data-drawer-open="leistung-neu"[^>]*>Leistung hinzufügen<\/button>/s', $html);
        $this->assertStringContainsString('id="leistung-neu" data-drawer hidden', $html);
    }

    public function test_rows_and_drawers_carry_the_hooks_of_the_script(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup, 'Färben', ['color' => '#aa3300', 'description' => 'Farbe & Pflege']);
        $service->staff()->attach($setup['staff']->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent();
        $drawers = $this->drawers($html);

        // Das Formular zum Anlegen setzt zurück und lädt neu, das zum Bearbeiten lädt neu; keines trägt die Speicherleiste.
        $this->assertSame(1, preg_match('/<form id="form-leistung-neu"[^>]*>/', $html, $new));
        $this->assertStringContainsString('data-ajax data-reset-after-save data-reload-after-save', $new[0]);
        $this->assertSame(1, preg_match('/<form id="form-leistung-'.$service->id.'"[^>]*>/', $html, $edit));
        $this->assertStringContainsString('data-ajax data-reload-after-save', $edit[0]);
        $this->assertStringNotContainsString('data-reset-after-save', $edit[0]);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*data-savebar/', $html);
        // Die Rückfrage in der Zeile lädt nach dem Löschen neu und benennt die Leistung für Screenreader.
        $this->assertMatchesRegularExpression('/<form id="leistung-loeschen-'.$service->id.'"[^>]*data-ajax\s+data-reload-after-save/', $html);
        $this->assertStringContainsString('<span class="sr-only"> „Färben“</span>', $html);
        // Felder: gespeicherte Werte, Farbe, Beschreibung und die angehakte Person.
        $this->assertMatchesRegularExpression('/id="svc-'.$service->id.'-name" name="name" type="text" value="Färben"/', $drawers);
        $this->assertMatchesRegularExpression('/type="color" name="color" value="#aa3300"/', $drawers);
        $this->assertStringContainsString('Farbe &amp; Pflege</textarea>', $drawers);
        $this->assertMatchesRegularExpression('/name="staff_ids\[\]" value="'.$setup['staff']->id.'" checked/', $drawers);
        $this->assertStringContainsString('<input type="hidden" name="is_active" value="0">', $drawers);
        // Die Leistung zeigt ihre Farbe als Punkt vor dem Namen.
        $this->assertStringContainsString('background-color: #aa3300', $this->mainContent($html));
    }

    public function test_a_stored_color_outside_the_color_field_notation_falls_back_to_the_default(): void
    {
        $setup = $this->salon();
        // Die Spalte (varchar(9)) fasst auch Werte, die das Farbfeld des Browsers nicht kennt.
        $alpha = $this->service($setup, 'Pflege', ['color' => '#aabbccdd']);
        $style = $this->service($setup, 'Haarkur', ['color' => 'red;top:0']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent();

        $this->assertStringNotContainsString('top:0', $html);
        $this->assertStringNotContainsString('#aabbccdd', $html);
        foreach ([$alpha, $style] as $service) {
            $this->assertMatchesRegularExpression('/id="svc-'.$service->id.'-color" type="color" name="color" value="#6b7280"/', $html);
        }
        $this->assertStringNotContainsString('background-color', $this->mainContent($html));
    }

    public function test_a_paused_service_says_so_next_to_its_icon(): void
    {
        $setup = $this->salon();
        $this->service($setup, 'Pflege', ['is_active' => false]);
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent());

        $this->assertStringContainsString('Noch niemandem zugeordnet · Pausiert', $main);
        // Das Wort steht sichtbar in der Zeile; das Symbol liest es nicht noch einmal vor.
        $this->assertStringNotContainsString('<span class="sr-only">Pausiert</span>', $main);
        $this->assertStringContainsString('title="Pausiert"', $main);
    }

    public function test_services_are_listed_in_natural_order(): void
    {
        $setup = $this->salon();
        $first = $this->service($setup, 'Pflege 10');
        $second = $this->service($setup, 'Pflege 2');
        $third = $this->service($setup, 'Balayage', ['sort_order' => 5]);
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent());

        $order = [];
        foreach ([$first, $second, $third] as $service) {
            $order[$service->name] = strpos($main, 'data-drawer-open="leistung-'.$service->id.'"');
        }
        asort($order);
        $this->assertSame(['Pflege 2', 'Pflege 10', 'Balayage'], array_keys($order));
    }

    public function test_the_page_uses_no_inline_handlers(): void
    {
        foreach (['index', 'partials/fields'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/services/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_restaurants_get_an_explanation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $service = $this->service($setup);
        $this->clearTenantContext();

        $message = 'Leistungen gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.';
        $this->actingAs($owner)->getJson('/admin/services')
            ->assertForbidden()
            ->assertJsonPath('message', $message);
        $this->actingAs($owner)->get('/admin/services')->assertForbidden()->assertSee($message);

        // Auch die Schreibwege antworten so; es entsteht, ändert und verschwindet nichts.
        $payload = ['name' => 'Neu', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1'];
        $this->actingAs($owner)->postJson('/admin/services', $payload)->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->putJson("/admin/services/{$service->id}", ['name' => 'Anders'] + $payload)->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->deleteJson("/admin/services/{$service->id}")->assertForbidden()->assertJsonPath('message', $message);

        $this->assertSame(1, Service::withoutGlobalScopes()->count());
        $this->assertSame('Schnitt', $service->fresh()->name);
        $this->assertSame(0, AuditLog::where('action', 'like', 'service.%')->count());
    }

    public function test_the_team_without_the_right_is_turned_away(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/services')->assertForbidden();
        $this->actingAs($host)->postJson('/admin/services', ['name' => 'Neu', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1'])->assertForbidden();
        $this->actingAs($host)->deleteJson("/admin/services/{$service->id}")->assertForbidden();
        $this->assertSame(1, Service::withoutGlobalScopes()->count());
    }

    // ── Preis ────────────────────────────────────────────────────────────

    public function test_the_price_comes_in_euros_and_from_the_setup_assistant_in_cents(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Färben', 'duration_minutes' => 90, 'price' => '49.90', 'color' => '#aa3300', 'is_active' => '1',
            'staff_ids' => [$setup['staff']->id],
        ])->assertOk()->assertJsonPath('message', 'Leistung „Färben“ angelegt.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Beratung', 'duration_minutes' => 30, 'price_minor' => '1500', 'is_active' => '1',
        ])->assertOk();

        $this->assertSame(4990, Service::withoutGlobalScopes()->where('name', 'Färben')->value('price_minor'));
        $this->assertSame(1500, Service::withoutGlobalScopes()->where('name', 'Beratung')->value('price_minor'));
    }

    public function test_euros_become_cents_without_rounding_errors(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        // Kommazahlen wie 1.15 (114.99999999999999 als float) und 0.57 (56.99999999999999) bleiben exakt.
        $cases = [
            '12.5' => 1250, '0.29' => 29, '1.15' => 115, '0.57' => 57, '4.35' => 435, '19.99' => 1999, '0.1' => 10,
            '0' => 0, '0.00' => 0, '7' => 700, '07.50' => 750, '1000' => 100000, '999.99' => 99999,
        ];
        foreach ($cases as $euros => $cents) {
            $this->actingAs($setup['owner'])->postJson('/admin/services', [
                'name' => "Preis {$euros}", 'duration_minutes' => 30, 'price' => (string) $euros, 'is_active' => '1',
            ])->assertOk();
            $this->assertSame($cents, Service::withoutGlobalScopes()->where('name', "Preis {$euros}")->value('price_minor'), "{$euros} €");
        }

        // Zahlen aus JSON (Fließkomma und ganze Zahl) ergeben dasselbe.
        foreach ([[12.5, 1250], [49.9, 4990], [20, 2000]] as [$euros, $cents]) {
            $name = 'Zahl '.$cents;
            $this->actingAs($setup['owner'])->postJson('/admin/services', [
                'name' => $name, 'duration_minutes' => 30, 'price' => $euros, 'is_active' => '1',
            ])->assertOk();
            $this->assertSame($cents, Service::withoutGlobalScopes()->where('name', $name)->value('price_minor'), "{$euros} €");
        }

        // Das Ergebnis einer Fließkomma-Rechnung (0.1 + 0.2) mit Rauschen an der 17. Stelle wird abgelehnt und nie als Betrag gespeichert.
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Rauschen', 'duration_minutes' => 30, 'price' => '0.30000000000000004', 'is_active' => '1',
        ])->assertStatus(422)->assertJsonPath('errors.price.0', fn (string $message) => str_contains($message, 'höchstens zwei Nachkommastellen'));
        $this->assertNull(Service::withoutGlobalScopes()->where('name', 'Rauschen')->first());
    }

    public function test_a_price_in_the_wrong_format_explains_the_format(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $format = 'Bitte den Preis in Euro als Zahl mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 49.90. Mit 0 steht auf der Buchungsseite „auf Anfrage“.';

        // Das Zahlenfeld des Browsers reicht „12,50“ als 12.50 weiter; ein Komma kommt nur von anderen Absendern.
        foreach (['12,50', '12.345', '-5', '1e2', 'zehn', '12.', '.5', '1 000', '€ 12', ['12']] as $price) {
            $this->actingAs($setup['owner'])->postJson('/admin/services', [
                'name' => 'Preis', 'duration_minutes' => 30, 'price' => $price, 'is_active' => '1',
            ])->assertStatus(422)->assertJsonPath('errors.price.0', $format);
        }

        $this->actingAs($setup['owner'])->postJson('/admin/services', ['name' => 'Preis', 'duration_minutes' => 30, 'is_active' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.price.0', 'Bitte den Preis in Euro eintragen, etwa 35 oder 49.90. Mit 0 steht auf der Buchungsseite „auf Anfrage“.')
            ->assertJsonMissingPath('errors.price_minor');
        $this->assertSame(0, Service::withoutGlobalScopes()->count());
    }

    public function test_the_price_in_cents_of_the_setup_assistant_is_checked_too(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $base = ['name' => 'Beratung', 'duration_minutes' => 30, 'is_active' => '1'];

        $this->actingAs($setup['owner'])->postJson('/admin/services', $base + ['price_minor' => '15.5'])
            ->assertStatus(422)
            ->assertJsonPath('errors.price_minor.0', 'Bitte den Preis in Cent als ganze Zahl eintragen, etwa 4990 für 49,90 €.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', $base + ['price_minor' => '-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.price_minor.0', 'Der Preis muss mindestens 0 betragen. Bitte 0 oder mehr eintragen.');
        // Die Grenze steht in Euro, so wie die Seite sie nennt: 1.000 € sind 100000 Cent.
        $this->actingAs($setup['owner'])->postJson('/admin/services', $base + ['price_minor' => '100001'])
            ->assertStatus(422)
            ->assertJsonPath('errors.price_minor.0', 'Der Preis darf höchstens 1.000 € betragen. Bitte einen kleineren Betrag eintragen.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', $base + ['price_minor' => '100000'])->assertOk();
        $this->assertSame(100000, Service::withoutGlobalScopes()->where('name', 'Beratung')->value('price_minor'));
    }

    public function test_the_setup_assistant_payload_still_creates_a_service(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        // Der Assistent sendet formular-kodiert, ohne Farbe, Beschreibung und Personen.
        $payload = ['name' => 'Haarschnitt Herren', 'duration_minutes' => '30', 'price_minor' => '0', 'is_active' => '1'];

        $this->actingAs($setup['owner'])->postJson('/admin/services', $payload)->assertOk()->assertJsonPath('message', 'Leistung „Haarschnitt Herren“ angelegt.');
        $service = Service::withoutGlobalScopes()->where('name', 'Haarschnitt Herren')->firstOrFail();
        $this->assertSame(0, $service->price_minor);
        $this->assertTrue($service->is_active);
        $this->assertNull($service->color);
        $this->assertSame(0, $service->staff()->count());

        // Ohne Skript geht es zurück zur Seite.
        $this->actingAs($setup['owner'])->post('/admin/services', ['name' => 'Beratung'] + $payload)->assertRedirect()->assertSessionHas('success', 'Leistung „Beratung“ angelegt.');
        // Ohne Angabe gilt die Leistung als online buchbar; ein Schalter für die Seite kommt immer mit.
        $this->assertTrue(Service::withoutGlobalScopes()->where('name', 'Beratung')->firstOrFail()->is_active);
    }

    // ── Meldungen und Grenzen ────────────────────────────────────────────

    public function test_a_rejected_entry_names_the_cause_and_the_next_step(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->clearTenantContext();
        $valid = ['name' => 'Pflege', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1'];
        $send = fn (array $change) => $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", $change + $valid);

        $send(['name' => ''])->assertStatus(422)->assertJsonPath('errors.name.0', 'Bitte einen Namen für die Leistung eintragen.');
        $send(['name' => str_repeat('a', 121)])->assertJsonPath('errors.name.0', 'Der Name darf höchstens 120 Zeichen lang sein. Bitte kürze ihn.');
        $send(['description' => str_repeat('a', 1001)])->assertJsonPath('errors.description.0', 'Die Beschreibung darf höchstens 1000 Zeichen lang sein. Bitte kürze sie.');
        $send(['duration_minutes' => ''])->assertJsonPath('errors.duration_minutes.0', 'Bitte die Dauer in Minuten als ganze Zahl eintragen, etwa 45.');
        $send(['duration_minutes' => 'lang'])->assertJsonPath('errors.duration_minutes.0', 'Bitte die Dauer in Minuten als ganze Zahl eintragen, etwa 45.');
        $send(['duration_minutes' => 30.5])->assertJsonPath('errors.duration_minutes.0', 'Bitte die Dauer in Minuten als ganze Zahl eintragen, etwa 45.');
        $send(['duration_minutes' => 4])->assertJsonPath('errors.duration_minutes.0', 'Die Dauer muss mindestens 5 Minuten betragen. Bitte eine größere Zahl eintragen.');
        $send(['duration_minutes' => 481])->assertJsonPath('errors.duration_minutes.0', 'Die Dauer darf höchstens 480 Minuten betragen. Bitte eine kleinere Zahl eintragen.');
        $send(['price' => '1000.01'])->assertJsonPath('errors.price.0', 'Der Preis darf höchstens 1.000 € betragen. Bitte einen kleineren Betrag eintragen.');
        $send(['color' => 'rot'])->assertJsonPath('errors.color.0', 'Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.');
        $send(['is_active' => 'vielleicht'])->assertJsonPath('errors.is_active.0', 'Bitte „Online buchbar“ ein- oder ausschalten.');

        $this->assertSame('Schnitt', $service->fresh()->name);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config([
            'swayy.salon.service_duration_min' => 10, 'swayy.salon.service_duration_max' => 120, 'swayy.salon.service_price_max' => 100,
            'swayy.salon.service_name_max' => 15, 'swayy.salon.service_description_max' => 60,
        ]);
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => str_repeat('a', 16), 'description' => str_repeat('b', 61), 'duration_minutes' => 150, 'price' => '120', 'is_active' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 15 Zeichen lang sein. Bitte kürze ihn.')
            ->assertJsonPath('errors.description.0', 'Die Beschreibung darf höchstens 60 Zeichen lang sein. Bitte kürze sie.')
            ->assertJsonPath('errors.duration_minutes.0', 'Die Dauer darf höchstens 120 Minuten betragen. Bitte eine kleinere Zahl eintragen.')
            ->assertJsonPath('errors.price.0', 'Der Preis darf höchstens 100 € betragen. Bitte einen kleineren Betrag eintragen.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Kurz', 'duration_minutes' => 9, 'price' => '5', 'is_active' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.duration_minutes.0', 'Die Dauer muss mindestens 10 Minuten betragen. Bitte eine größere Zahl eintragen.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => str_repeat('a', 15), 'description' => str_repeat('b', 60), 'duration_minutes' => 120, 'price' => '100', 'is_active' => '1',
        ])->assertOk();

        // Die Felder der Seite tragen dieselben Grenzen.
        $html = (string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*maxlength="15"/', $html);
        $this->assertMatchesRegularExpression('/name="duration_minutes"[^>]*min="10" max="120"/', $html);
        $this->assertMatchesRegularExpression('/name="price"[^>]*max="100"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="description"[^>]*maxlength="60"/', $html);
    }

    public function test_limits_end_at_the_columns_and_are_documented(): void
    {
        // services.name ist varchar(255); duration_minutes smallint (32767); price_minor integer (2147483647 Cent).
        [$config, $warnings] = $this->swayyConfig(['SWAYY_SERVICE_NAME_MAX' => '4000', 'SWAYY_SERVICE_DESCRIPTION_MAX' => '90000']);
        $this->assertSame(255, $config['salon']['service_name_max']);
        $this->assertSame(5000, $config['salon']['service_description_max']);
        $this->assertStringContainsString('SWAYY_SERVICE_NAME_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_SERVICE_DESCRIPTION_MAX', $warnings);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(120, $config['salon']['service_name_max']);
        $this->assertSame(1000, $config['salon']['service_description_max']);
        $this->assertLessThanOrEqual(255, $config['salon']['service_name_max']);
        $this->assertLessThanOrEqual(32767, $config['salon']['service_duration_max']);
        $this->assertLessThanOrEqual(2147483647, $config['salon']['service_price_max'] * 100);

        $example = (string) file_get_contents(base_path('.env.example'));
        foreach (['SWAYY_SERVICE_NAME_MAX', 'SWAYY_SERVICE_DESCRIPTION_MAX'] as $variable) {
            $this->assertStringContainsString("# {$variable}=", $example);
        }
    }

    public function test_the_largest_allowed_values_fit_their_columns(): void
    {
        config([
            'swayy.salon.service_name_max' => 255, 'swayy.salon.service_duration_max' => 1440,
            'swayy.salon.service_price_max' => 100000, 'swayy.salon.service_description_max' => 5000,
        ]);
        $setup = $this->salon();
        $this->clearTenantContext();

        $name = str_repeat('ä', 255);
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => $name, 'description' => str_repeat('b', 5000), 'duration_minutes' => 1440, 'price' => '100000', 'color' => '#ffffff', 'is_active' => '1',
        ])->assertOk();

        $service = Service::withoutGlobalScopes()->where('name', $name)->firstOrFail();
        $this->assertSame(1440, $service->duration_minutes);
        $this->assertSame(10000000, $service->price_minor);
        $this->assertSame(5000, mb_strlen((string) $service->description));
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => $name.'x', 'duration_minutes' => 30, 'price' => '1', 'is_active' => '1',
        ])->assertStatus(422);
    }

    public function test_the_switch_is_required_when_editing_and_optional_for_the_setup_assistant(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->clearTenantContext();
        $payload = ['name' => 'Schnitt', 'duration_minutes' => 60, 'price' => '35'];

        // Die Seite sendet den Schalter immer mit; fehlt er beim Bearbeiten, wird nicht still auf „online buchbar“ gesetzt.
        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.is_active.0', 'Bitte „Online buchbar“ ein- oder ausschalten.');
        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", $payload + ['is_active' => '0'])->assertOk();
        $this->assertFalse($service->fresh()->is_active);
        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", $payload + ['is_active' => '1'])->assertOk();
        $this->assertTrue($service->fresh()->is_active);
    }

    // ── Personen ─────────────────────────────────────────────────────────

    public function test_staff_of_another_location_is_refused_with_a_reason(): void
    {
        $setup = $this->salon();
        $other = $this->createTenantSetup();
        $foreign = StaffMember::create([
            'tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id, 'name' => 'Fremd', 'is_active' => true,
        ]);
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $elsewhere = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $otherLocation->id, 'name' => 'Woanders', 'is_active' => true,
        ]);
        $gone = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Weg', 'is_active' => true,
        ]);
        $gone->delete();
        $service = $this->service($setup);
        $service->staff()->attach($setup['staff']->id);
        $this->clearTenantContext();
        $reason = 'Mindestens eine gewählte Person gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Personen neu wählen.';
        $payload = ['name' => 'Schnitt 2', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1'];

        foreach ([$foreign->id, $elsewhere->id, $gone->id, 999999] as $unknown) {
            $this->actingAs($setup['owner'])->postJson('/admin/services', $payload + ['staff_ids' => [$setup['staff']->id, $unknown]])
                ->assertStatus(422)->assertJsonPath('errors.staff_ids.0', $reason);
            $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", ['name' => 'Anders'] + $payload + ['staff_ids' => [$unknown]])
                ->assertStatus(422)->assertJsonPath('errors.staff_ids.0', $reason);
        }

        // Nichts wurde angelegt oder geändert, und niemand wurde abgekoppelt.
        $this->assertSame(1, Service::withoutGlobalScopes()->count());
        $this->assertSame('Schnitt', $service->fresh()->name);
        $this->assertSame([$setup['staff']->id], $service->staff()->pluck('staff_members.id')->all());
        $this->assertSame(0, $foreign->services()->withoutGlobalScopes()->count());

        // Eigene Personen gehen durch.
        $this->actingAs($setup['owner'])->postJson('/admin/services', $payload + ['staff_ids' => [$setup['staff']->id]])->assertOk();
        $created = Service::withoutGlobalScopes()->where('name', 'Schnitt 2')->firstOrFail();
        $this->assertSame([$setup['staff']->id], $created->staff()->withoutGlobalScopes()->pluck('staff_members.id')->all());
    }

    public function test_the_selection_of_people_has_a_size_limit_and_no_duplicates(): void
    {
        $setup = $this->salon();
        StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Ben', 'is_active' => true,
        ]);
        $this->clearTenantContext();
        $payload = ['name' => 'Schnitt 2', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1'];
        $invalid = 'Die Auswahl der Personen ist ungültig. Bitte die Seite neu laden und die Personen neu wählen.';

        // Mehr Einträge, als der Standort Personen hat, kommen nie von der Seite und gehen nicht in die Abfrage.
        $this->actingAs($setup['owner'])->postJson('/admin/services', $payload + ['staff_ids' => range(1, 500)])
            ->assertStatus(422)
            ->assertJsonPath('errors.staff_ids.0', 'Mindestens eine gewählte Person gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Personen neu wählen.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', $payload + ['staff_ids' => [$setup['staff']->id, $setup['staff']->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.staff_ids.0', $invalid);
        foreach ([['abc'], [[1]], [1.5], 'alle'] as $unusable) {
            $this->actingAs($setup['owner'])->postJson('/admin/services', $payload + ['staff_ids' => $unusable])
                ->assertStatus(422)->assertJsonPath('errors.staff_ids.0', $invalid);
        }
        $this->assertSame(0, Service::withoutGlobalScopes()->count());
    }

    public function test_editing_keeps_inactive_people_who_the_drawer_does_not_list(): void
    {
        $setup = $this->salon();
        $inactive = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Urlaub', 'is_active' => false,
        ]);
        $second = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Ben', 'is_active' => true,
        ]);
        $service = $this->service($setup);
        $service->staff()->attach([$setup['staff']->id, $inactive->id]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->getContent();
        $this->assertStringNotContainsString('Urlaub', $this->drawers($html));
        $this->assertStringContainsString('1 Std. · 35,00 € · Anna', $html);

        // Die Auswahl der Seite zeigt nur aktive Personen; wer pausiert, bleibt der Leistung zugeordnet.
        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", [
            'name' => 'Schnitt', 'duration_minutes' => 60, 'price' => '35', 'is_active' => '1', 'staff_ids' => [$second->id],
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$second->id, $inactive->id], $service->staff()->pluck('staff_members.id')->all());

        // Wer aktiv ist und abgewählt wird, fällt weg.
        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", [
            'name' => 'Schnitt', 'duration_minutes' => 60, 'price' => '35', 'is_active' => '1',
        ])->assertOk();
        $this->assertSame([$inactive->id], $service->staff()->pluck('staff_members.id')->all());
    }

    // ── Mandanten und Standorte ──────────────────────────────────────────

    public function test_services_of_other_tenants_and_other_locations_answer_404(): void
    {
        $setup = $this->salon();
        $foreign = $this->createTenantSetup();
        $foreignService = $this->service($foreign, 'Fremd');
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $elsewhere = $this->service(['tenant' => $setup['tenant'], 'location' => $otherLocation], 'Woanders');
        $this->clearTenantContext();
        $payload = ['name' => 'X', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1', 'staff_ids' => [$setup['staff']->id]];

        foreach ([$foreignService, $elsewhere] as $service) {
            $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", $payload)->assertNotFound();
            $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")->assertNotFound();
        }
        $this->actingAs($setup['owner'])->putJson('/admin/services/999999', $payload)->assertNotFound();

        foreach ([$foreignService, $elsewhere] as $service) {
            $this->assertNull(Service::withoutGlobalScopes()->find($service->id)->deleted_at);
            $this->assertNotSame('X', Service::withoutGlobalScopes()->find($service->id)->name);
            $this->assertSame(0, $service->staff()->withoutGlobalScopes()->count());
        }
        $this->assertSame(0, AuditLog::where('action', 'like', 'service.%')->count());

        // Die Seite führt nur die Leistungen des gewählten Standorts.
        $this->actingAs($setup['owner'])->get('/admin/services')->assertOk()->assertDontSee('Fremd')->assertDontSee('Woanders');
    }

    // ── Löschen ──────────────────────────────────────────────────────────

    public function test_a_service_with_upcoming_appointments_stays(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->appointment($setup, $service);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.service.0', '„Schnitt“ ist noch für einen kommenden Termin gebucht. Bitte den Termin zuerst stornieren oder bis nach dem Termin warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Online buchbar“.');
        $this->assertNotNull($service->fresh());
        $this->assertSame(0, AuditLog::where('action', 'service.deleted')->count());

        $free = $this->service($setup, 'Pflege');
        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$free->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Leistung „Pflege“ gelöscht.');
        $this->assertNull(Service::find($free->id));
        $this->assertSame(1, AuditLog::where('action', 'service.deleted')->count());

        // Ohne Skript kommt die Ablehnung auf der Seite an.
        $this->actingAs($setup['owner'])->from('/admin/services')->delete("/admin/services/{$service->id}")
            ->assertRedirect('/admin/services')
            ->assertSessionHasErrors('service');
    }

    public function test_the_delete_check_counts_every_kind_of_appointment_once(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $other = $this->service($setup, 'Pflege');
        // Kein Hindernis: vorbei, abgesagt, nicht erschienen, andere Leistung.
        $this->appointment($setup, $service, ReservationStatus::Confirmed, -2);
        $this->appointment($setup, $service, ReservationStatus::CancelledByGuest);
        $this->appointment($setup, $service, ReservationStatus::NoShow);
        $this->appointment($setup, $other);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")->assertOk();
        $this->assertNull(Service::find($service->id));
        // Der Eintrag bleibt als gelöscht erhalten; die vergangenen Termine verweisen weiter auf ihn.
        $this->assertNotNull(Service::withTrashed()->find($service->id)->deleted_at);

        // Zwei Termine, einer über die Zuordnung mehrerer Leistungen (Kombination) und einer über die erste Leistung.
        $setup2 = $this->salon();
        $combo = $this->service($setup2, 'Färben');
        $this->appointment($setup2, $combo, ReservationStatus::Confirmed, 1, true);
        $this->appointment($setup2, $combo, ReservationStatus::PendingConfirmation, 2);
        $this->clearTenantContext();

        $this->actingAs($setup2['owner'])->deleteJson("/admin/services/{$combo->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.service.0', '„Färben“ ist noch für 2 kommende Termine gebucht. Bitte die Termine zuerst stornieren oder bis nach den Terminen warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Online buchbar“.');
    }

    public function test_one_appointment_with_both_links_counts_once(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $reservation = $this->appointment($setup, $service);
        $reservation->services()->attach($service->id, ['sort_order' => 0, 'duration_minutes' => 60, 'price_minor' => 3500]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.service.0', fn (string $message) => str_contains($message, 'für einen kommenden Termin gebucht'));
    }

    public function test_a_second_delete_of_the_same_service_logs_nothing_more(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $this->actingAs($setup['owner']);
        $request = Request::create('/', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $setup['owner']);
        $controller = app(ServiceController::class);

        // Beide Aufrufe halten dasselbe Modell, wie zwei Anfragen, die die Leistung vor dem Löschen geladen haben.
        foreach (range(1, 2) as $call) {
            $response = $controller->destroy($request, $service);
            $this->assertSame(200, $response->getStatusCode(), "Aufruf {$call}");
            $this->assertSame('Leistung „Schnitt“ gelöscht.', $response->getData(true)['message']);
        }

        $this->assertSame(1, AuditLog::where('action', 'service.deleted')->count());
    }

    public function test_deleting_locks_the_row_of_the_service_before_the_check_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->salon();
        $service = $this->service($setup);
        $this->clearTenantContext();

        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")->assertOk();

        // Die Sperre (FOR UPDATE) hält gleichzeitige Buchungen der Leistung an, bis die Prüfung auf kommende Termine und das
        // Löschen abgeschlossen sind; sie steht vor der Prüfung.
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from "services"') && str_contains($sql, 'for update'));
        $check = $queries->search(fn (string $sql) => str_contains($sql, 'from "reservations"') && str_contains($sql, 'count(*)'));
        $delete = $queries->search(fn (string $sql) => str_contains($sql, 'update "services" set "deleted_at"'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($check);
        $this->assertNotFalse($delete);
        $this->assertTrue($lock < $check && $check < $delete, 'Reihenfolge: Sperre, Prüfung, Löschen');
    }

    // ── Buchung einer entfernten Leistung ────────────────────────────────

    public function test_a_booking_for_a_service_removed_in_the_meantime_is_refused(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $removed = $this->service($setup, 'Pflege');
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);
        $booking = fn (array $serviceIds) => [
            'party_size' => 1, 'start_local' => $start, 'duration_minutes' => 60, 'source' => 'manual', 'guest_name' => 'Bea',
            'service_id' => $serviceIds[0], 'service_ids' => $serviceIds, 'staff_member_id' => $setup['staff']->id,
            'skip_availability_check' => true,
        ];

        // Eine Buchung hat die Leistungen geladen, dann wurde „Pflege“ gelöscht, danach kommt der Aufruf.
        $removed->delete();
        foreach ([[$removed->id], [$service->id, $removed->id]] as $ids) {
            try {
                app(ReservationLifecycleService::class)->create($setup['location'], $booking($ids));
                $this->fail('Die Buchung ist mit einer gelöschten Leistung durchgegangen.');
            } catch (ValidationException $e) {
                $this->assertSame(
                    'Mindestens eine gewählte Leistung wurde gerade entfernt. Bitte die Leistungen neu wählen.',
                    $e->errors()['service_ids'][0],
                );
            }
        }
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Eine Leistung eines anderen Standorts gilt als nicht vorhanden.
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $elsewhere = $this->service(['tenant' => $setup['tenant'], 'location' => $otherLocation], 'Woanders');
        $this->expectException(ValidationException::class);
        app(ReservationLifecycleService::class)->create($setup['location'], $booking([$elsewhere->id]));
    }

    public function test_a_booking_stores_all_its_services_inside_its_transaction(): void
    {
        $setup = $this->salon();
        $first = $this->service($setup);
        $second = $this->service($setup, 'Pflege', ['duration_minutes' => 30, 'price_minor' => 1500]);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 1, 'start_local' => $start, 'duration_minutes' => 90, 'source' => 'manual', 'guest_name' => 'Bea',
            'service_id' => $first->id, 'service_ids' => [$second->id, $first->id], 'staff_member_id' => $setup['staff']->id,
            'skip_availability_check' => true,
        ]);

        // Die Zuordnung steht schon nach dem Anlegen da, mit Reihenfolge und den Werten zum Zeitpunkt der Buchung.
        $rows = DB::table('reservation_service')->where('reservation_id', $reservation->id)->orderBy('sort_order')->get();
        $this->assertSame([$second->id, $first->id], $rows->pluck('service_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([30, 60], $rows->pluck('duration_minutes')->map(fn ($minutes) => (int) $minutes)->all());
        $this->assertSame([1500, 3500], $rows->pluck('price_minor')->map(fn ($price) => (int) $price)->all());
    }

    public function test_a_booking_locks_its_services_in_a_shared_way_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->salon();
        $service = $this->service($setup);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        DB::enableQueryLog();
        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 1, 'start_local' => $start, 'duration_minutes' => 60, 'source' => 'manual', 'guest_name' => 'Bea',
            'service_id' => $service->id, 'staff_member_id' => $setup['staff']->id, 'skip_availability_check' => true,
        ]);

        // FOR SHARE hält ein gleichzeitiges Löschen (FOR UPDATE) an, bis die Buchung festgeschrieben ist; Buchungen untereinander warten nicht.
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from "services"') && str_contains($sql, 'for share'));
        $insert = $queries->search(fn (string $sql) => str_starts_with($sql, 'insert into "reservations"'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($insert);
        $this->assertTrue($lock < $insert, 'Die Sperre steht vor dem Anlegen der Buchung.');
    }

    // ── Protokoll ────────────────────────────────────────────────────────

    public function test_changes_are_logged_with_the_stored_values(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Färben', 'duration_minutes' => 90, 'price' => '49.9', 'is_active' => '1', 'staff_ids' => [$setup['staff']->id],
        ])->assertOk();
        $service = Service::withoutGlobalScopes()->where('name', 'Färben')->firstOrFail();

        $created = AuditLog::where('action', 'service.created')->firstOrFail();
        // Der Preis steht in Cent, so wie das Protokoll ihn als Euro-Betrag anzeigt (Feld „price_minor“).
        $this->assertSame(4990, $created->new_values['price_minor']);
        $this->assertArrayNotHasKey('price', $created->new_values);
        $this->assertSame([$setup['staff']->id], $created->new_values['staff_ids']);

        $this->actingAs($setup['owner'])->putJson("/admin/services/{$service->id}", [
            'name' => 'Färben lang', 'duration_minutes' => 120, 'price' => '59', 'is_active' => '0',
        ])->assertOk()->assertJsonPath('message', 'Leistung „Färben lang“ gespeichert.');

        $updated = AuditLog::where('action', 'service.updated')->firstOrFail();
        $this->assertSame('Färben', $updated->old_values['name']);
        $this->assertSame(4990, $updated->old_values['price_minor']);
        $this->assertSame([$setup['staff']->id], $updated->old_values['staff_ids']);
        $this->assertSame('Färben lang', $updated->new_values['name']);
        $this->assertSame(5900, $updated->new_values['price_minor']);
        $this->assertFalse($updated->new_values['is_active']);
        $this->assertSame([], $updated->new_values['staff_ids']);
        $this->assertSame('Leistung angelegt', $created->actionLabel());
        $this->assertSame('Personen', (new AuditLog(['action' => 'service.updated', 'old_values' => ['staff_ids' => [1]], 'new_values' => ['staff_ids' => [2]]]))->fieldChanges()[0]['label']);
    }

    // ── Ohne Skript ──────────────────────────────────────────────────────

    public function test_a_rejected_form_without_script_keeps_the_input_and_marks_the_fields(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->from('/admin/services')->followingRedirects()->post('/admin/services', [
            'name' => 'Färben', 'description' => 'Mit Pflege', 'duration_minutes' => 90, 'price' => 'zehn', 'color' => '#112233',
            'is_active' => '0', 'staff_ids' => [$setup['staff']->id],
        ])->assertOk()->getContent();
        $new = $this->newDrawer($html);

        $this->assertStringContainsString('Mit Pflege</textarea>', $new);
        $this->assertMatchesRegularExpression('/type="color" name="color" value="#112233"/', $new);
        $this->assertMatchesRegularExpression('/name="staff_ids\[\]" value="'.$setup['staff']->id.'" checked/', $new);
        $this->assertMatchesRegularExpression('/id="svc-neu-active" type="checkbox" name="is_active" value="1"(?! checked)/', $new);
        // Das Preisfeld ist als fehlerhaft markiert und verweist auf den Text.
        $this->assertMatchesRegularExpression('/id="svc-neu-price"[^>]*aria-describedby="[^"]*svc-neu-price-error[^"]*"[^>]*aria-invalid="true"/s', $new);
        $this->assertStringContainsString('id="svc-neu-price-error"', $new);
        $this->assertStringContainsString('Bitte den Preis in Euro als Zahl mit Punkt', $new);
    }

    public function test_hand_built_fields_point_to_their_errors(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $valid = ['name' => 'Färben', 'duration_minutes' => 90, 'price' => '20', 'is_active' => '1'];
        $post = fn (array $payload): string => $this->newDrawer((string) $this->actingAs($setup['owner'])->from('/admin/services')
            ->followingRedirects()->post('/admin/services', $valid + $payload)->assertOk()->getContent());

        $new = $post(['description' => str_repeat('a', 1001), 'color' => 'rot']);
        $this->assertMatchesRegularExpression('/<textarea[^>]*aria-describedby="[^"]*svc-neu-description-error[^"]*"[^>]*aria-invalid="true"/s', $new);
        $this->assertMatchesRegularExpression('/type="color"[^>]*aria-describedby="[^"]*svc-neu-color-error[^"]*"[^>]*aria-invalid="true"/s', $new);

        // Eine unbekannte Person lehnt der Server nach der Prüfung der Felder ab; der Text hängt an der Gruppe der Personen.
        $new = $post(['staff_ids' => [999999]]);
        $this->assertMatchesRegularExpression('/<fieldset id="svc-neu-personen"[^>]*aria-describedby="[^"]*svc-neu-personen-error/s', $new);
        $this->assertStringContainsString('id="svc-neu-personen-error"', $new);
        $this->assertMatchesRegularExpression('/name="staff_ids\[\]"[^>]*aria-invalid="true"/', $new);
    }

    /**
     * Lädt config/swayy.php mit den gesetzten Umgebungsvariablen.
     *
     * @param  array<string, string>  $env
     * @return array{0: array<string, mixed>, 1: string} Konfiguration und Warnungen im Fehlerprotokoll
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Service;
use App\Models\Tag;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Was beim Umzug erhalten bleiben muss.
 *
 * Jeder Fall hier ging vorher still verloren oder landete falsch: geloeschte
 * Tische standen im Ziel wieder aktiv da, Reservierungen haengten am falschen
 * gleichnamigen Tisch, der Buchungszeitpunkt kippte auf den Importtag, und die
 * Markierungen der Gaeste waren fort.
 */
class AccountMoveIntegrityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function ziel(): Tenant
    {
        return Tenant::factory()->create(['plan_id' => Plan::factory()]);
    }

    // ── Papierkorb bleibt Papierkorb ──────────────────────────────────────

    /**
     * Der Export nimmt weich Geloeschtes mit, der Import warf deleted_at weg -
     * ein aus dem Raum genommener Tisch stand danach wieder im Tischplan, war
     * online buchbar und wurde von der Tischvergabe wieder belegt.
     */
    public function test_a_soft_deleted_table_stays_deleted_after_the_move(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tables'][0]->delete();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $tische = RestaurantTable::withoutGlobalScopes()->withTrashed()
            ->whereIn('location_id', $ziel->locations()->pluck('id'))
            ->get();

        $this->assertCount(3, $tische);
        $this->assertSame(1, $tische->filter(fn ($t) => $t->trashed())->count());
    }

    // ── Gleichnamige Tische ───────────────────────────────────────────────

    /**
     * "T2" geloescht, "T2" neu angelegt: In der Namenskarte gewann die zuletzt
     * gelesene Zeile, und die Reservierungen BEIDER Tische landeten am selben
     * neuen Tisch. Aufgeloest wird jetzt ueber die Quell-IDs.
     */
    public function test_reservations_follow_their_own_table_not_a_namesake(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);

        $alt = $setup['tables'][0];
        $neu = RestaurantTable::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $setup['room']->id,
            'name' => $alt->name,
            'min_capacity' => 1,
            'max_capacity' => 4,
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $anAlt = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Completed,
            'source' => 'staff', 'guest_name_snapshot' => 'Alt',
        ]);
        $anAlt->tables()->attach($alt->id);

        $anNeu = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->addDay()->toDateString(),
            'start_at' => $start->addDay()->utc(), 'end_at' => $start->addDay()->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'staff', 'guest_name_snapshot' => 'Neu',
        ]);
        $anNeu->tables()->attach($neu->id);

        // Erst jetzt loeschen, damit beide gleichnamig im Export stehen.
        $alt->delete();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $importiert = Reservation::withoutGlobalScopes()->withTrashed()
            ->where('tenant_id', $ziel->id)->get()->keyBy('guest_name_snapshot');

        $tischAlt = $importiert['Alt']->tables()->withTrashed()->first();
        $tischNeu = $importiert['Neu']->tables()->withTrashed()->first();

        $this->assertNotNull($tischAlt);
        $this->assertNotNull($tischNeu);
        $this->assertNotSame($tischAlt->id, $tischNeu->id, 'Beide Reservierungen haengen am selben Tisch.');
        $this->assertTrue($tischAlt->trashed());
        $this->assertFalse($tischNeu->trashed());
    }

    // ── Zeitstempel ───────────────────────────────────────────────────────

    /**
     * Ohne created_at traegt jede uebernommene Buchung den Importtag als
     * Buchungszeitpunkt - und der Statusverlauf steht danach in beliebiger
     * Reihenfolge, weil alle Eintraege dieselbe Sekunde tragen.
     */
    public function test_the_booking_time_survives_the_move(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $gebucht = now()->subMonths(3)->startOfMinute();
        $reservation->forceFill(['created_at' => $gebucht])->saveQuietly();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $importiert = Reservation::withoutGlobalScopes()->where('tenant_id', $ziel->id)->sole();

        $this->assertSame($gebucht->toDateTimeString(), $importiert->created_at->toDateTimeString());
    }

    // ── Markierungen der Gaeste ───────────────────────────────────────────

    public function test_guest_tags_survive_the_move(): void
    {
        $setup = $this->createTenantSetup();

        $tag = Tag::create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast',
            'color' => '#00ff00', 'scope' => 'guest',
        ]);
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier',
        ]);
        $guest->tags()->attach($tag->id);

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $importiert = Guest::withoutGlobalScopes()->where('tenant_id', $ziel->id)->sole();

        $this->assertSame(['Stammgast'], $importiert->tags->pluck('name')->all());
    }

    /**
     * Die drei preferred_-Spalten sind echte Fremdschluessel. Ungemappt zeigten
     * sie auf die IDs der Quellinstallation.
     */
    public function test_a_guests_favourite_table_points_into_the_new_installation(): void
    {
        $setup = $this->createTenantSetup();

        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier',
            'preferred_location_id' => $setup['location']->id,
            'preferred_room_id' => $setup['room']->id,
            'preferred_table_id' => $setup['tables'][2]->id,
        ]);

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $importiert = Guest::withoutGlobalScopes()->where('tenant_id', $ziel->id)->sole();
        $zielTische = RestaurantTable::withoutGlobalScopes()
            ->whereIn('location_id', $ziel->locations()->pluck('id'))->pluck('id');

        $this->assertNotNull($importiert->preferred_table_id);
        $this->assertNotSame($guest->preferred_table_id, $importiert->preferred_table_id);
        $this->assertTrue($zielTische->contains($importiert->preferred_table_id));
    }

    // ── Fehlerhafte Datei ─────────────────────────────────────────────────

    /**
     * Ein Datenbankfehler beim Import darf nicht ins Formular.
     *
     * Seine Meldung traegt die fehlgeschlagene Anweisung samt eingesetzter
     * Werte - also Gastdaten aus der Datei. `catch (RuntimeException)` reichte
     * dafuer nicht: `QueryException` erbt ueber `PDOException` ebenfalls davon.
     */
    public function test_a_database_error_during_import_does_not_leak_guest_data(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        // Ohne Nachnamen scheitert der Insert an der NOT-NULL-Bedingung - und
        // die Fehlermeldung fuehrt die eingesetzten Werte mit, also E-Mail und
        // Telefonnummer aus der Datei.
        $export['guests'] = [[
            'id' => 9001,
            'first_name' => 'Klara',
            'email' => 'klara-geheim@example.test',
            'phone' => '+49 30 4711',
        ]];

        $datei = UploadedFile::fake()->createWithContent(
            'export.json',
            json_encode($export, JSON_UNESCAPED_UNICODE)
        );

        $antwort = $this->actingAs($owner)->post('/admin/account/import', [
            'file' => $datei,
            'confirm' => '1',
        ]);

        $antwort->assertSessionHasErrors('file');
        $fehler = implode(' ', session('errors')->get('file'));

        $this->assertNotSame('', $fehler, 'Es kam gar keine Meldung.');
        $this->assertStringNotContainsString('klara-geheim@example.test', $fehler);
        $this->assertStringNotContainsString('+49 30 4711', $fehler);
        $this->assertStringNotContainsString('insert into', mb_strtolower($fehler));
    }

    // ── Salontermine ──────────────────────────────────────────────────────

    /**
     * In der Reservierungszeile steht nur die ERSTE Leistung. Ohne die
     * Zusammenstellung wurde aus drei Leistungen eine - waehrend die Zeit
     * weiter fuer alle drei belegt ist und die vereinbarten Preise fehlen.
     */
    public function test_a_salon_appointment_keeps_its_full_service_composition(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);

        $leistungen = collect(['Schnitt' => 60, 'Farbe' => 45, 'Tönung' => 45])
            ->map(fn (int $dauer, string $name) => Service::create([
                'tenant_id' => $setup['tenant']->id,
                'location_id' => $setup['location']->id,
                'name' => $name,
                'duration_minutes' => $dauer,
                'price_minor' => $dauer * 100,
                'is_active' => true,
            ]));

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 1, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addMinutes(150)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
            'service_id' => $leistungen->first()->id,
        ]);
        $reservation->services()->sync(
            $leistungen->values()->mapWithKeys(fn (Service $s, int $i) => [
                $s->id => ['sort_order' => $i, 'duration_minutes' => $s->duration_minutes, 'price_minor' => $s->price_minor],
            ])->all()
        );

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $ziel = $this->ziel();
        app(AccountImportService::class)->import($ziel, $export);

        $importiert = Reservation::withoutGlobalScopes()->where('tenant_id', $ziel->id)->sole();

        $this->assertCount(3, $importiert->services);
        $this->assertSame(['Schnitt', 'Farbe', 'Tönung'], $importiert->services->pluck('name')->all());
        $this->assertSame(6000, $importiert->services->first()->pivot->price_minor);
    }
}

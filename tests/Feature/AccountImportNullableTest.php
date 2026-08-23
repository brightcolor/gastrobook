<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\AccountImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ein unaufloesbarer Verweis darf hoechstens seine Zeile kosten.
 *
 * Der Import laeuft in EINER Transaktion. Landet irgendwo ein NULL in einer
 * Spalte, die die Datenbank verlangt, bricht das Einfuegen - und mit ihm der
 * komplette Umzug. Der Fall stand viermal im Bestand: floor_zones.room_id,
 * feedback_requests.reservation_id, marketing_sends.guest_id und
 * restaurant_tables.room_id.
 *
 * Darum hier nicht Einzelfaelle, sondern zwei Netze: die Liste gegen das
 * Schema, und - das eigentliche Netz - der Import selbst gegen eine Datei
 * voller kaputter Verweise.
 */
class AccountImportNullableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Kern: Eine Datei, in der JEDER Fremdschluessel ins Leere zeigt, muss
     * durchlaufen. Was sich nicht aufloesen laesst, faellt weg - der Rest
     * kommt an.
     *
     * Dieser Test haengt an keiner Liste. Er faellt auch dann, wenn jemand
     * einen neuen Abschnitt hinzufuegt und die Liste vergisst.
     */
    public function test_an_import_full_of_dangling_references_survives(): void
    {
        $tenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);

        // 4711 gibt es in der Zuordnung nie: Der Import hat gerade erst
        // angefangen, und die einzige Zeile, die wirklich entsteht, ist der
        // Standort.
        $tot = 4711;
        $daten = [
            'format' => 'swayy-account-export',
            'format_version' => 1,
            'locations' => [[
                'id' => 1, 'name' => 'Beispielbetrieb', 'slug' => 'beispiel',
                'timezone' => 'Europe/Berlin',
            ]],
            'rooms' => [['id' => 1, 'location_id' => $tot, 'name' => 'Saal']],
            'tables' => [['id' => 1, 'location_id' => 1, 'room_id' => $tot, 'name' => 'T1', 'min_capacity' => 1, 'max_capacity' => 4]],
            'table_combinations' => [['id' => 1, 'location_id' => $tot, 'name' => 'K1', 'min_capacity' => 4, 'max_capacity' => 8]],
            'floor_zones' => [['id' => 1, 'location_id' => 1, 'room_id' => $tot, 'name' => 'Terrasse']],
            'opening_hours' => [['id' => 1, 'location_id' => $tot, 'weekday' => 1, 'opens_at' => '12:00', 'closes_at' => '22:00']],
            'blackout_periods' => [['id' => 1, 'location_id' => 1, 'room_id' => $tot, 'starts_at' => '2027-01-01 00:00:00', 'ends_at' => '2027-01-02 00:00:00', 'reason' => 'Umbau']],
            'table_blocks' => [['id' => 1, 'location_id' => 1, 'restaurant_table_id' => $tot, 'starts_at' => '2027-01-01 00:00:00', 'ends_at' => '2027-01-02 00:00:00']],
            'services' => [['id' => 1, 'location_id' => $tot, 'name' => 'Schnitt', 'duration_minutes' => 60, 'price_minor' => 3500, 'currency' => 'EUR']],
            'staff_members' => [['id' => 1, 'location_id' => $tot, 'name' => 'Anna']],
            'staff_working_hours' => [['id' => 1, 'staff_member_id' => $tot, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '17:00']],
            'events' => [['id' => 1, 'location_id' => 1, 'room_id' => $tot, 'title' => 'Weinprobe', 'slug' => 'weinprobe', 'starts_at' => '2027-01-01 18:00:00', 'ends_at' => '2027-01-01 22:00:00', 'capacity' => 10, 'currency' => 'EUR', 'status' => 'published']],
            'deposit_rules' => [['id' => 1, 'location_id' => 1, 'room_id' => $tot, 'event_id' => $tot, 'service_id' => $tot, 'name' => 'Standard', 'amount_minor' => 1000]],
            'guests' => [['id' => 1, 'first_name' => 'Klara', 'last_name' => 'Meier', 'email' => 'klara@example.test', 'preferred_location_id' => $tot, 'preferred_room_id' => $tot, 'preferred_table_id' => $tot]],
            'guest_notes' => [['id' => 1, 'guest_id' => $tot, 'body' => 'Notiz']],
            'reservations' => [['id' => 1, 'location_id' => 1, 'guest_id' => $tot, 'event_id' => $tot, 'service_id' => $tot, 'staff_member_id' => $tot, 'deposit_rule_id' => $tot, 'party_size' => 2, 'reservation_date' => '2027-01-01', 'start_at' => '2027-01-01 18:00:00', 'end_at' => '2027-01-01 20:00:00', 'timezone' => 'Europe/Berlin', 'status' => 'confirmed', 'source' => 'online', 'guest_name_snapshot' => 'Klara Meier']],
            'reservation_notes' => [['id' => 1, 'reservation_id' => $tot, 'body' => 'Notiz']],
            'event_bookings' => [['id' => 1, 'event_id' => $tot, 'reservation_id' => $tot, 'guest_id' => $tot, 'ticket_count' => 2, 'guest_name' => 'Eva', 'guest_email' => 'eva@example.test', 'status' => 'confirmed']],
            'waitlist_entries' => [['id' => 1, 'location_id' => 1, 'guest_id' => $tot, 'reservation_id' => $tot, 'guest_name' => 'Bert', 'party_size' => 2, 'desired_date' => '2027-01-01', 'status' => 'waiting']],
            'payments' => [['id' => 1, 'reservation_id' => $tot, 'event_booking_id' => $tot, 'provider' => 'stripe', 'type' => 'deposit', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'pending']],
            'refunds' => [['id' => 1, 'reservation_id' => $tot, 'event_booking_id' => $tot, 'payment_intent_id' => $tot, 'provider' => 'stripe', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'pending']],
            'feedback_requests' => [['id' => 1, 'location_id' => 1, 'reservation_id' => $tot, 'token' => 'x', 'sent_at' => '2027-01-01 12:00:00']],
            'marketing_campaigns' => [['id' => 1, 'location_id' => $tot, 'name' => 'Geburtstag', 'type' => 'birthday', 'subject' => 'Hallo', 'body' => 'Text']],
        ];

        $ergebnis = app(AccountImportService::class)->import($tenant, $daten);

        // Der Standort haengt an nichts und muss ankommen.
        $this->assertSame(1, $ergebnis['locations']);
        $this->assertDatabaseHas('locations', ['tenant_id' => $tenant->id, 'slug' => 'beispiel']);

        // Alles mit totem Pflichtverweis faellt weg - lautlos, aber ohne den
        // Umzug mitzunehmen.
        foreach (['rooms', 'tables', 'table_combinations', 'floor_zones', 'opening_hours',
            'table_blocks', 'services', 'staff_members', 'staff_working_hours',
            'reservation_notes', 'guest_notes', 'event_bookings', 'feedback_requests',
            'marketing_campaigns', 'refunds', 'payments'] as $abschnitt) {
            $this->assertSame(0, $ergebnis[$abschnitt] ?? 0, $abschnitt.' haette verworfen werden muessen.');
        }

        // Und alles, dessen Verweise verzichtbar sind, kommt an - mit leerer
        // Spalte statt gar nicht.
        foreach (['blackout_periods', 'events', 'deposit_rules', 'guests', 'reservations', 'waitlist_entries'] as $abschnitt) {
            $this->assertSame(1, $ergebnis[$abschnitt] ?? 0, $abschnitt.' haette ankommen muessen.');
        }

        $this->assertDatabaseHas('events', ['tenant_id' => $tenant->id, 'slug' => 'weinprobe', 'room_id' => null]);
        $this->assertDatabaseHas('guests', ['tenant_id' => $tenant->id, 'email' => 'klara@example.test', 'preferred_table_id' => null]);
    }

    /**
     * Und die Liste daneben: Was sie fuer verzichtbar erklaert, muss die
     * Datenbank auch leer lassen duerfen. Weicht sie ab, verwirft der Import
     * Zeilen, die er behalten sollte - kein Absturz mehr, aber stiller
     * Datenverlust.
     */
    public function test_every_column_the_list_calls_optional_accepts_null(): void
    {
        $verstoesse = [];

        foreach (AccountImportService::NULLABLE_AFTER_MAPPING as $abschnitt => [$modelClass, $spalten]) {
            /** @var Model $model */
            $model = new $modelClass;
            $tabelle = $model->getTable();
            $schema = collect(Schema::getColumns($tabelle))->keyBy('name');

            foreach ($spalten as $spalte) {
                $eintrag = $schema->get($spalte);

                if ($eintrag === null) {
                    $verstoesse[] = "{$abschnitt}: {$tabelle}.{$spalte} gibt es gar nicht";

                    continue;
                }

                if (! $eintrag['nullable']) {
                    $verstoesse[] = "{$abschnitt}: {$tabelle}.{$spalte} ist NOT NULL";
                }
            }
        }

        $this->assertSame([], $verstoesse, "Diese Spalten stehen als verzichtbar in der Liste, die Datenbank verlangt sie aber:\n".implode("\n", $verstoesse));
    }

    /**
     * Die Prüfung darüber läuft auf SQLite, live läuft PostgreSQL. Sie sagt
     * damit etwas über die MIGRATIONEN aus, nicht über die Produktivdatenbank:
     * Solange keine Migration die Spaltendefinition je Treiber unterscheidet,
     * bauen beide dasselbe Schema. Eine von Hand geänderte oder aus einem
     * Abzug wiederhergestellte Datenbank deckt sie nicht ab.
     *
     * Dieser Test hält die Voraussetzung fest: Die Nullbarkeit einer Spalte
     * wird ausschliesslich beim Anlegen festgelegt, und eine
     * Blueprint-Spaltendefinition ist für beide Treiber dieselbe. Wer sie
     * nachträglich ändert – per `->change()` oder per rohem ALTER COLUMN –,
     * kann das je Treiber unterschiedlich tun, und dann sagt die Prüfung
     * darüber nichts mehr.
     */
    public function test_no_migration_alters_column_nullability_afterwards(): void
    {
        $verdaechtig = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $datei) {
            $inhalt = (string) file_get_contents($datei);

            if (preg_match('/->change\(\)/', $inhalt)
                || preg_match('/ALTER\s+(TABLE\s+\S+\s+)?ALTER\s+COLUMN/i', $inhalt)) {
                $verdaechtig[] = basename($datei);
            }
        }

        $this->assertSame([], $verdaechtig, "Diese Migrationen aendern Spalten nachtraeglich - die SQLite-Pruefung sagt dann nichts mehr ueber PostgreSQL:\n".implode("\n", $verdaechtig));
    }
}

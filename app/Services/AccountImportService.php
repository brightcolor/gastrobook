<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AccountImportException;
use App\Models\BlackoutPeriod;
use App\Models\DepositRule;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\FeedbackRequest;
use App\Models\FeedbackResponse;
use App\Models\FloorZone;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\GuestNote;
use App\Models\Location;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\NotificationTemplate;
use App\Models\OpeningHour;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\ReservationStatusHistory;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Service;
use App\Models\SpecialOpeningHour;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Models\TableBlock;
use App\Models\TableCombination;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Counterpart to AccountExportService: reads an export file back in so a
 * business can actually move — or restore a backup.
 *
 * The import is additive (nothing existing is deleted) and runs in a single
 * transaction: either everything lands or nothing does. Internal ids from the
 * source system are never reused; every record gets a fresh id and references
 * are remapped. Access tokens and booking codes are regenerated, because the
 * export deliberately contains none.
 */
class AccountImportService
{
    /** Columns that must never be taken from the file. */
    private const DROP = [
        'id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'user_id', 'approved_by', 'requested_by',
        'code', 'manage_token', 'token', 'secret',
    ];

    /**
     * Zeitstempel, die nach dem Speichern nachgetragen werden.
     *
     * Sie stehen in DROP, weil sie nicht massenzuweisbar sein sollen - aber
     * verworfen gehoeren sie nicht: Ohne created_at traegt jede Buchung den
     * Importtag als Buchungszeitpunkt, und ohne deleted_at entsteht jede weich
     * geloeschte Zeile im Ziel als AKTIVER Datensatz neu. Ein Tisch, den der
     * Betrieb aus dem Raum genommen hat, stuende danach wieder im Tischplan
     * und waere online buchbar.
     */
    private const CARRY = ['created_at', 'updated_at', 'deleted_at'];

    /** @var array<string, array<int|string, int>> old id → new id, per entity */
    private array $map = [];

    /** @var array<string, array<int, string>> Tabellenname → Spalten */
    private array $columns = [];

    /** @var array<string, array<int, string>> Tabellenname → Spalten, die NULL zulassen */
    private array $nullable = [];

    /**
     * Validate the payload and report what an import would create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public function preview(array $data): array
    {
        $this->assertValid($data);

        $counts = [];
        foreach ($this->importableSections() as $section) {
            $rows = $data[$section] ?? [];
            if (is_array($rows) && $rows !== []) {
                $counts[$section] = count($rows);
            }
        }

        return $counts;
    }

    /**
     * Import an export payload into the given tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int> imported record counts
     */
    public function import(Tenant $tenant, array $data): array
    {
        $this->assertValid($data);
        $this->map = [];

        return DB::transaction(function () use ($tenant, $data) {
            $done = [];

            $done['locations'] = $this->locations($tenant, $data);
            $done['rooms'] = $this->rooms($tenant, $data);
            $done['tables'] = $this->tables($tenant, $data);
            $done['table_combinations'] = $this->combinations($tenant, $data);
            // room_id ist hier PFLICHT - eine Zone ohne Raum laesst die Datenbank
            // nicht zu. Ein unaufloesbarer Verweis verwirft darum die Zone.
            $done['floor_zones'] = $this->simple($tenant, $data, 'floor_zones', FloorZone::class, ['location_id' => 'locations', 'room_id' => 'rooms']);
            $done['opening_hours'] = $this->simple($tenant, $data, 'opening_hours', OpeningHour::class, ['location_id' => 'locations']);
            $done['special_opening_hours'] = $this->simple($tenant, $data, 'special_opening_hours', SpecialOpeningHour::class, ['location_id' => 'locations']);
            $done['blackout_periods'] = $this->simple($tenant, $data, 'blackout_periods', BlackoutPeriod::class, ['location_id' => 'locations', 'room_id' => 'rooms']);
            $done['tags'] = $this->simple($tenant, $data, 'tags', Tag::class, [], ['name', 'scope']);
            $done['table_blocks'] = $this->simple($tenant, $data, 'table_blocks', TableBlock::class, ['location_id' => 'locations', 'restaurant_table_id' => 'tables']);
            $done['notification_templates'] = $this->simple($tenant, $data, 'notification_templates', NotificationTemplate::class, ['location_id' => 'locations'], ['location_id', 'key', 'locale']);
            $done['services'] = $this->simple($tenant, $data, 'services', Service::class, ['location_id' => 'locations']);
            $done['staff_members'] = $this->simple($tenant, $data, 'staff_members', StaffMember::class, ['location_id' => 'locations']);
            $done['staff_working_hours'] = $this->simple($tenant, $data, 'staff_working_hours', StaffWorkingHour::class, ['staff_member_id' => 'staff_members']);
            $done['staff_absences'] = $this->simple($tenant, $data, 'staff_absences', StaffAbsence::class, ['staff_member_id' => 'staff_members']);

            // Reihenfolge = Abhaengigkeiten. Alles, worauf spaeter gezeigt wird,
            // muss vorher importiert sein – sonst steht in der Fremdschluessel-
            // spalte die ID der QUELLinstallation: entweder eine Verletzung, die
            // die ganze Transaktion abbricht, oder eine stille Falschverknuepfung.
            // Welche Verweise verzichtbar sind, steht in NULLABLE_AFTER_MAPPING -
            // ein Event ohne aufloesbaren Raum ist immer noch ein Event.
            $done['events'] = $this->simple($tenant, $data, 'events', Event::class, ['location_id' => 'locations', 'room_id' => 'rooms']);
            $done['deposit_rules'] = $this->simple($tenant, $data, 'deposit_rules', DepositRule::class, [
                'location_id' => 'locations',
                'room_id' => 'rooms',
                'event_id' => 'events',
                'service_id' => 'services',
            ]);

            // Die drei preferred_-Spalten sind echte Fremdschluessel. Ungemappt
            // steht dort die ID der Quellinstallation: entweder eine Verletzung,
            // die den ganzen Import abbricht, oder - auf einer geteilten
            // Installation - ein stiller Zeiger auf den Tisch eines FREMDEN
            // Betriebs.
            $done['guests'] = $this->guests($tenant, $data);
            $done['guest_notes'] = $this->simple($tenant, $data, 'guest_notes', GuestNote::class, ['guest_id' => 'guests']);
            $done['guest_consents'] = $this->simple($tenant, $data, 'guest_consents', GuestConsent::class, ['guest_id' => 'guests']);

            $done['reservations'] = $this->reservations($tenant, $data);
            $done['reservation_notes'] = $this->simple($tenant, $data, 'reservation_notes', ReservationNote::class, ['reservation_id' => 'reservations']);
            $done['reservation_status_history'] = $this->simple($tenant, $data, 'reservation_status_history', ReservationStatusHistory::class, ['reservation_id' => 'reservations']);

            $done['event_bookings'] = $this->simple($tenant, $data, 'event_bookings', EventBooking::class, [
                'event_id' => 'events',
                'reservation_id' => 'reservations',
                'guest_id' => 'guests',
            ]);
            $done['waitlist_entries'] = $this->simple($tenant, $data, 'waitlist_entries', WaitlistEntry::class, [
                'location_id' => 'locations',
                'guest_id' => 'guests',
                'reservation_id' => 'reservations',
            ]);
            $done['payments'] = $this->simple($tenant, $data, 'payments', PaymentIntent::class, ['reservation_id' => 'reservations', 'event_booking_id' => 'event_bookings']);
            $done['refunds'] = $this->refunds($tenant, $data);
            // reservation_id ist PFLICHT - eine Feedback-Anfrage ohne Buchung gibt
            // es nicht.
            $done['feedback_requests'] = $this->simple($tenant, $data, 'feedback_requests', FeedbackRequest::class, ['location_id' => 'locations', 'reservation_id' => 'reservations']);
            $done['feedback_responses'] = $this->simple($tenant, $data, 'feedback_responses', FeedbackResponse::class, ['location_id' => 'locations', 'feedback_request_id' => 'feedback_requests']);

            $done['marketing_campaigns'] = $this->simple($tenant, $data, 'marketing_campaigns', MarketingCampaign::class, ['location_id' => 'locations']);
            // Die Versandhistorie wandert mit, sonst bekommt jeder Gast nach dem
            // Umzug seinen Geburtstagsgruss oder die Win-back-Mail ein zweites Mal.
            $done['marketing_sends'] = $this->simple($tenant, $data, 'marketing_sends', MarketingSend::class, [
                'marketing_campaign_id' => 'marketing_campaigns',
                'guest_id' => 'guests',
            ]);

            return array_filter($done);
        });
    }

    /** @return array<int, string> */
    private function importableSections(): array
    {
        return [
            'locations', 'rooms', 'tables', 'table_combinations', 'floor_zones',
            'opening_hours', 'special_opening_hours', 'blackout_periods',
            'tags', 'table_blocks', 'deposit_rules', 'notification_templates',
            'services', 'staff_members', 'staff_working_hours', 'staff_absences',
            'guests', 'guest_notes', 'guest_consents',
            'reservations', 'reservation_notes', 'reservation_status_history',
            'events', 'event_bookings', 'waitlist_entries',
            'payments', 'refunds', 'feedback_requests', 'feedback_responses',
            'marketing_campaigns', 'marketing_sends',
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertValid(array $data): void
    {
        if (($data['format'] ?? null) !== 'swayy-account-export') {
            throw new AccountImportException('Diese Datei ist kein Swayy-Account-Export.');
        }
        if ((int) ($data['format_version'] ?? 0) !== 1) {
            throw new AccountImportException('Diese Export-Datei stammt aus einer nicht unterstützten Version.');
        }
        if (! isset($data['locations']) || ! is_array($data['locations']) || $data['locations'] === []) {
            throw new AccountImportException('Die Datei enthält keine Standorte – ohne Standort kann nicht importiert werden.');
        }
    }

    /** Locations need unique slugs within the installation. */
    private function locations(Tenant $tenant, array $data): int
    {
        $count = 0;
        foreach ($data['locations'] as $row) {
            $attrs = $this->attrs($row);
            $attrs['slug'] = $this->uniqueLocationSlug($attrs['slug'] ?? Str::slug($attrs['name'] ?? 'standort'));

            $location = new Location($attrs);
            $location->tenant_id = $tenant->id;
            $location->save();
            $this->carryTimestamps($location, $row);

            $this->remember('locations', $row['id'] ?? null, $location->id);

            // Settings belong to exactly one location.
            $settings = collect($data['location_settings'] ?? [])
                ->firstWhere('location_id', $row['id'] ?? null);
            $location->settings()->create(
                array_merge($this->attrs($settings ?? []), ['tenant_id' => $tenant->id])
            );

            $count++;
        }

        return $count;
    }

    private function uniqueLocationSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'standort';
        $candidate = $slug;
        $i = 2;
        while (Location::withoutGlobalScopes()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i++;
        }

        return $candidate;
    }

    private function rooms(Tenant $tenant, array $data): int
    {
        return $this->simple($tenant, $data, 'rooms', Room::class, ['location_id' => 'locations']);
    }

    /** Tables additionally keep a name→id lookup so reservations can be linked. */
    private function tables(Tenant $tenant, array $data): int
    {
        $count = 0;
        $ersatz = [];

        foreach ($data['tables'] ?? [] as $row) {
            $attrs = $this->attrs($row);
            // Selbstverweis: zeigt auf einen Tisch, den es hier noch nicht gibt.
            // Wird nach dem Durchlauf nachgetragen.
            unset($attrs['backup_table_id']);

            // Ueber remap wie ueberall sonst - und damit auch room_id. Der
            // Raum ist hier PFLICHT; geprueft wurde bisher nur der Standort,
            // und ein unaufloesbarer Raum brach den ganzen Import ab.
            if (! $this->remap('tables', 'restaurant_tables', [
                'location_id' => 'locations',
                'room_id' => 'rooms',
            ], $row, $attrs)) {
                continue;
            }

            $table = new RestaurantTable($attrs);
            $table->tenant_id = $tenant->id;
            $table->save();
            $this->carryTimestamps($table, $row);

            $this->remember('tables', $row['id'] ?? null, $table->id);

            if (! empty($row['backup_table_id'])) {
                $ersatz[$table->id] = $row['backup_table_id'];
            }

            // Name lookup (per location) als RUECKFALL fuer aeltere Dateien.
            // Nicht ueberschreiben und Papierkorbzeilen heraushalten: Zwei
            // Tische duerfen denselben Namen tragen - durch weiches Loeschen
            // sogar der Normalfall ("T2" geloescht, "T2" neu). Gewann die
            // zuletzt gelesene Zeile den Schluessel, landeten die
            // Reservierungen BEIDER Tische am selben neuen Tisch.
            $schluessel = $attrs['location_id'].'|'.$table->name;
            if (empty($row['deleted_at']) && ! isset($this->map['table_names'][$schluessel])) {
                $this->map['table_names'][$schluessel] = $table->id;
            }

            $count++;
        }

        foreach ($ersatz as $neueId => $alteErsatzId) {
            // Ohne den Zeitstempel: Ein Update ueber den Query Builder stempelt
            // updated_at auf jetzt und macht damit den Uebertrag von oben
            // wieder zunichte.
            $tisch = RestaurantTable::withoutGlobalScopes()->withTrashed()->find($neueId);
            $tisch?->forceFill([
                'backup_table_id' => $this->mapped('tables', $alteErsatzId),
                'updated_at' => $tisch->updated_at,
            ])->saveQuietly();
        }

        return $count;
    }

    /**
     * Die Tische einer Reservierung oder Kombination auflösen.
     *
     * Bevorzugt ueber die Quell-IDs. Der Name ist nur der Rueckfall fuer
     * Dateien aelterer Fassungen - er ist nicht eindeutig.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private function resolveTables(array $row, int $locationId): array
    {
        $ids = [];

        foreach ($row['table_ids'] ?? [] as $alteId) {
            $neu = $this->mapped('tables', $alteId);
            if ($neu !== null) {
                $ids[] = $neu;
            }
        }

        // Am VORHANDENSEIN des Feldes entscheiden, nicht am Ergebnis: Eine
        // neue Datei, deren Tische sich alle nicht aufloesen lassen, faellt
        // sonst auf die Namenskarte zurueck und haengt an einem Gleichnamigen.
        if (array_key_exists('table_ids', $row)) {
            return array_values(array_unique($ids));
        }

        foreach ($row['table_names'] ?? [] as $name) {
            $id = $this->map['table_names'][$locationId.'|'.$name] ?? null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function combinations(Tenant $tenant, array $data): int
    {
        $count = 0;
        foreach ($data['table_combinations'] ?? [] as $row) {
            $locationId = $this->mapped('locations', $row['location_id'] ?? null);
            if ($locationId === null) {
                continue;
            }

            $attrs = $this->attrs($row);
            $attrs['location_id'] = $locationId;
            $combo = new TableCombination($attrs);
            $combo->tenant_id = $tenant->id;
            $combo->save();
            $this->carryTimestamps($combo, $row);

            $ids = $this->resolveTables($row, $locationId);
            if ($ids !== []) {
                $combo->tables()->sync($ids);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Gaeste samt ihrer Markierungen.
     *
     * Die drei preferred_-Spalten sind echte Fremdschluessel. Ungemappt stuende
     * dort die ID der Quellinstallation: entweder eine Verletzung, die den
     * ganzen Import abbricht, oder - auf einer geteilten Installation - ein
     * stiller Zeiger auf den Tisch eines FREMDEN Betriebs. Laesst sich der
     * Verweis nicht aufloesen, faellt nur die Vorliebe weg, nicht der Gast.
     *
     * @param  array<string, mixed>  $data
     */
    private function guests(Tenant $tenant, array $data): int
    {
        $tagIds = Tag::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id', 'name');
        $count = 0;

        foreach ($data['guests'] ?? [] as $row) {
            $attrs = $this->attrs($row);
            if (! $this->remap('guests', 'guests', [
                'preferred_location_id' => 'locations',
                'preferred_room_id' => 'rooms',
                'preferred_table_id' => 'tables',
            ], $row, $attrs)) {
                continue;
            }

            $guest = new Guest($attrs);
            $guest->tenant_id = $tenant->id;
            $guest->save();
            $this->carryTimestamps($guest, $row);

            $this->remember('guests', $row['id'] ?? null, $guest->id);

            $names = array_filter($row['tag_names'] ?? [], fn ($n) => $tagIds->has($n));
            if ($names !== []) {
                $guest->tags()->sync($tagIds->only($names)->values()->all());
            }

            $count++;
        }

        return $count;
    }

    /** Reservations relink tables, tags and services. */
    private function reservations(Tenant $tenant, array $data): int
    {
        $tagIds = Tag::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id', 'name');
        $count = 0;

        foreach ($data['reservations'] ?? [] as $row) {
            $locationId = $this->mapped('locations', $row['location_id'] ?? null);
            if ($locationId === null) {
                continue;
            }

            $attrs = $this->attrs($row);
            $attrs['location_id'] = $locationId;
            if (! $this->remap('reservations', 'reservations', [
                'guest_id' => 'guests',
                'event_id' => 'events',
                'service_id' => 'services',
                'staff_member_id' => 'staff_members',
                'deposit_rule_id' => 'deposit_rules',
            ], $row, $attrs)) {
                continue;
            }

            $reservation = new Reservation($attrs);
            $reservation->tenant_id = $tenant->id;
            // code + manage_token are regenerated by the model's creating hook.
            $reservation->save();
            $this->carryTimestamps($reservation, $row);

            $this->remember('reservations', $row['id'] ?? null, $reservation->id);

            $tableIds = $this->resolveTables($row, $locationId);
            if ($tableIds !== []) {
                $reservation->tables()->sync($tableIds);
            }

            $names = array_filter($row['tag_names'] ?? [], fn ($n) => $tagIds->has($n));
            if ($names !== []) {
                $reservation->tags()->sync($tagIds->only($names)->values()->all());
            }

            // Die Zusammenstellung eines Salontermins samt Preis- und
            // Dauerschnappschuss. In der Reservierungszeile steht nur die
            // ERSTE Leistung.
            $leistungen = [];
            foreach ($row['services'] ?? [] as $eintrag) {
                $neu = $this->mapped('services', $eintrag['service_id'] ?? null);
                if ($neu !== null) {
                    $leistungen[$neu] = [
                        'sort_order' => $eintrag['sort_order'] ?? 0,
                        'duration_minutes' => $eintrag['duration_minutes'] ?? null,
                        'price_minor' => $eintrag['price_minor'] ?? null,
                    ];
                }
            }
            if ($leistungen !== []) {
                $reservation->services()->sync($leistungen);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Refunds come along as history, but never as pending work.
     *
     * The export deliberately carries no provider references, and the target
     * installation has its own payment accounts. An imported refund that is
     * still open would be picked up by the scheduler and tried against a
     * provider that knows nothing about it — so open refunds are closed with a
     * clear reason and have to be handled by hand in the old system.
     */
    private function refunds(Tenant $tenant, array $data): int
    {
        $open = ['pending', 'approved', 'processing'];
        $count = 0;

        foreach ($data['refunds'] ?? [] as $row) {
            $attrs = $this->attrs($row);
            if (! $this->remap('refunds', 'refunds', [
                'reservation_id' => 'reservations',
                'event_booking_id' => 'event_bookings',
                'payment_intent_id' => 'payments',
            ], $row, $attrs)) {
                continue;
            }

            if (in_array($attrs['status'] ?? null, $open, true)) {
                $attrs['status'] = 'failed';
                $attrs['scheduled_for'] = null;
                $attrs['error'] = 'Beim Umzug übernommen – im alten System abschließen.';
            }

            $refund = new Refund($attrs);
            $refund->tenant_id = $tenant->id;
            $refund->save();
            $this->carryTimestamps($refund, $row);

            $this->remember('refunds', $row['id'] ?? null, $refund->id);
            $count++;
        }

        return $count;
    }

    /**
     * Spalten, in denen nach dem Umschluesseln NULL stehen darf.
     *
     * Ein Verweis, der sich in der Zielinstallation nicht aufloesen laesst,
     * wird bei diesen Spalten geleert, statt die ganze Zeile zu verwerfen -
     * eine Buchung ohne Raum ist immer noch eine Buchung. Alles, was hier
     * nicht steht, gilt als Pflichtverweis: Laesst er sich nicht aufloesen,
     * faellt die ZEILE weg.
     *
     * Der zweite Waechter steht in remap(): Was hier steht, die Datenbank aber
     * verlangt, wird trotzdem verworfen statt geleert. Denn ein NULL in einer
     * NOT-NULL-Spalte bricht das Einfuegen, und weil der ganze Import in EINER
     * Transaktion laeuft, reisst eine einzige Zeile ihn komplett mit. Diese
     * Liste kann sich also irren, ohne Schaden anzurichten.
     *
     * @var array<string, array{0: class-string<Model>, 1: array<int, string>}>
     */
    public const NULLABLE_AFTER_MAPPING = [
        'blackout_periods' => [BlackoutPeriod::class, ['room_id']],
        'events' => [Event::class, ['room_id']],
        'deposit_rules' => [DepositRule::class, ['room_id', 'event_id', 'service_id']],
        'guests' => [Guest::class, ['preferred_location_id', 'preferred_room_id', 'preferred_table_id']],
        'reservations' => [Reservation::class, ['guest_id', 'event_id', 'service_id', 'staff_member_id', 'deposit_rule_id']],
        'event_bookings' => [EventBooking::class, ['reservation_id', 'guest_id']],
        'waitlist_entries' => [WaitlistEntry::class, ['guest_id', 'reservation_id']],
        'payments' => [PaymentIntent::class, ['reservation_id', 'event_booking_id']],
        'refunds' => [Refund::class, ['reservation_id', 'event_booking_id', 'payment_intent_id']],
    ];

    /**
     * Abschnitte, deren Zeilen mindestens EINEN dieser Verweise behalten muessen.
     *
     * Jede Spalte darf einzeln leer sein - eine Zahlung haengt entweder an
     * einer Reservierung oder an einer Eventbuchung. Alle zusammen leer heisst
     * dagegen: eine Geldzeile ohne Bezug. Sie steht dann in der Liste, laesst
     * sich aber weder zuordnen noch ausloesen.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRES_ANY_OWNER = [
        'payments' => ['reservation_id', 'event_booking_id'],
        'refunds' => ['reservation_id', 'event_booking_id', 'payment_intent_id'],
    ];

    /**
     * Verweise umschluesseln und melden, ob die Zeile brauchbar bleibt.
     *
     * Die einzige Stelle, die entscheidet, ob ein unaufloesbarer Verweis die
     * Zeile kostet oder nur die Spalte. Vorher entschied das jeder Aufrufer
     * ueber ein eigenes Argument - und drei davon lagen falsch: Sie erklaerten
     * Spalten fuer verzichtbar, die die Datenbank verlangt. Der ganze Umzug
     * scheiterte dann an einer einzigen Zeile.
     *
     * @param  array<string, string>  $relations  Spalte => Abschnitt in der Zuordnung
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attrs
     * @return bool false heisst: Zeile verwerfen
     */
    private function remap(string $section, string $table, array $relations, array $row, array &$attrs): bool
    {
        $optional = self::NULLABLE_AFTER_MAPPING[$section][1] ?? [];

        foreach ($relations as $column => $entity) {
            $neu = $this->mapped($entity, $row[$column] ?? null);

            // Leeren nur, wenn es BEIDE erlauben: die Absicht oben und das
            // Schema. Das Schema hat das letzte Wort - eine falsche Zeile in
            // der Liste kostet dann hoechstens diese eine Zeile, nie den
            // ganzen Import.
            if ($neu === null
                && (! in_array($column, $optional, true) || ! $this->isNullable($table, $column))) {
                return false;
            }

            $attrs[$column] = $neu;
        }

        foreach (self::REQUIRES_ANY_OWNER[$section] ?? [] as $spalte) {
            if (($attrs[$spalte] ?? null) !== null) {
                return true;
            }
        }

        return ! isset(self::REQUIRES_ANY_OWNER[$section]);
    }

    /**
     * Laesst die Datenbank in dieser Spalte NULL zu?
     */
    private function isNullable(string $table, string $column): bool
    {
        $this->nullable[$table] ??= collect(Schema::getColumns($table))
            ->filter(fn (array $spalte) => (bool) $spalte['nullable'])
            ->pluck('name')
            ->all();

        return in_array($column, $this->nullable[$table], true);
    }

    /**
     * Generic importer for a section.
     *
     * @param  class-string  $modelClass
     * @param  array<string, string>  $relations  column => entity key in the id map
     * @param  array<int, string>  $unique  columns forming a natural key within the tenant.
     *                                      A match reuses the existing record instead of
     *                                      creating a duplicate — the target business may
     *                                      already have tags or templates of its own.
     */
    private function simple(Tenant $tenant, array $data, string $section, string $modelClass, array $relations = [], array $unique = []): int
    {
        $count = 0;
        $tabelle = (new $modelClass)->getTable();

        foreach ($data[$section] ?? [] as $row) {
            $attrs = $this->attrs($row);

            if (! $this->remap($section, $tabelle, $relations, $row, $attrs)) {
                continue;
            }

            if ($unique !== []) {
                $existing = $modelClass::withoutGlobalScopes()->where('tenant_id', $tenant->id);
                foreach ($unique as $column) {
                    $existing->where($column, $attrs[$column] ?? null);
                }
                $found = $existing->first();
                if ($found !== null) {
                    $this->remember($section, $row['id'] ?? null, $found->getKey());

                    continue;
                }
            }

            /** @var Model $model */
            $model = new $modelClass($attrs);
            $model->tenant_id = $tenant->id;
            $model->save();
            $this->carryTimestamps($model, $row);

            $this->remember($section, $row['id'] ?? null, $model->getKey());
            $count++;
        }

        return $count;
    }

    /** Strip everything that must not be carried over. */
    private function attrs(array $row): array
    {
        foreach (self::DROP as $key) {
            unset($row[$key]);
        }
        // export-only helper fields
        unset($row['table_names'], $row['table_ids'], $row['tag_names'], $row['services']);

        return $row;
    }

    /**
     * Zeitstempel aus der Datei nachtragen.
     *
     * Nach dem Speichern und ohne Modellereignisse: Die Zeitstempel stehen
     * bewusst nicht in der Massenzuweisung, sollen aber erhalten bleiben -
     * sonst kippt der Buchungszeitpunkt jeder uebernommenen Reservierung auf
     * den Importtag, und weich Geloeschtes steht im Ziel wieder aktiv da.
     *
     * @param  array<string, mixed>  $row
     */
    private function carryTimestamps(Model $model, array $row): void
    {
        $werte = [];
        foreach (self::CARRY as $spalte) {
            if (! empty($row[$spalte]) && $this->hasColumn($model, $spalte)) {
                $werte[$spalte] = $row[$spalte];
            }
        }

        if ($werte !== []) {
            $model->forceFill($werte)->saveQuietly();
        }
    }

    private function hasColumn(Model $model, string $column): bool
    {
        $tabelle = $model->getTable();
        $this->columns[$tabelle] ??= Schema::getColumnListing($tabelle);

        return in_array($column, $this->columns[$tabelle], true);
    }

    private function remember(string $entity, mixed $oldId, int $newId): void
    {
        if ($oldId !== null) {
            $this->map[$entity][(int) $oldId] = $newId;
        }
    }

    private function mapped(string $entity, mixed $oldId): ?int
    {
        if ($oldId === null) {
            return null;
        }

        return $this->map[$entity][(int) $oldId] ?? null;
    }
}

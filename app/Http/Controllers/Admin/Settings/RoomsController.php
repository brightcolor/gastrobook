<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\TableCombination;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\PlanLimitMessage;
use App\Support\ScheduleExceptionRules;
use App\Support\TableRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Räume & Tische: Räume, Tische und
 * Tischkombinationen des gewählten Standorts; wo die Tische stehen, legt der
 * Tischplan fest. Die Speicherwege (admin.settings.rooms.*, tables.*,
 * combinations.*) nutzen auch Einrichtungsassistent, Tischplan und bis Teil 3
 * die alte Sammelseite; ihre Antworten behalten deshalb ihre Felder.
 */
class RoomsController extends Controller
{
    use RespondsToSettingsForms;

    /** Größte Platzzahl einer Kombination: so viel fasst die Spalte (unsignedTinyInteger). */
    public const COMBINATION_CAPACITY_MAX = 255;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request): View
    {
        $location = $this->context->location();
        $tenant = $this->context->tenant();
        abort_if($location === null || $tenant === null, 404);
        abort_if($tenant->isSalon(), 403, __('Räume und Tische gibt es bei Restaurants. Salons richten unter „Platz & Zeiten“ Leistungen und Mitarbeiter ein.'));

        $rooms = $this->inOrder($location->rooms()
            ->where('is_active', true)
            ->with(['tables' => fn ($query) => $query->where('is_active', true)])
            ->get());
        // Jeder Tisch kennt seinen Raum, ohne ihn neu zu laden.
        $tables = $rooms->flatMap(function (Room $room) {
            $room->setRelation('tables', $this->inOrder($room->tables));

            return $room->tables->each(fn (RestaurantTable $table) => $table->setRelation('room', $room));
        });
        // Die Mitglieder einer Kombination mit Raum, Größe und "Kombinierbar", auch wenn ein Tisch nicht mehr kombinierbar ist.
        $combinations = $this->inOrder($location->tableCombinations()->with(['tables:id,name,room_id,min_capacity,max_capacity,joinable', 'tables.room:id,name'])->get());
        $byId = $tables->keyBy('id');
        $combinations->each(fn (TableCombination $combination) => $combination->setRelation('tables', $this->inOrder(
            $combination->tables->map(fn (RestaurantTable $member) => $byId->get($member->id) ?? $member),
        )));

        return view('admin.settings.rooms', [
            'rooms' => $rooms,
            'tables' => $tables,
            'joinable' => $tables->where('joinable', true)->values(),
            'combinations' => $combinations,
            'canRooms' => $request->user()->canInTenant('rooms.manage', $tenant, $location),
            'canFloorplan' => $request->user()->canInTenant('floorplan.update', $tenant, $location),
            'canBlock' => ScheduleExceptionRules::canManage($request->user(), $tenant, $location),
            'tableLimit' => $tenant->limit('max_tables'),
            'tablesUsed' => $this->limits->currentUsage($tenant, 'max_tables'),
            'capacityMax' => (int) config('swayy.tables.capacity_max'),
            'comboMax' => self::COMBINATION_CAPACITY_MAX,
            'roomSizeMax' => (int) config('swayy.tables.room_size_max_m'),
            'tableNameMax' => (int) config('swayy.tables.name_max'),
            'roomNameMax' => (int) config('swayy.tables.room_name_max'),
            'comboNameMax' => (int) config('swayy.tables.combination_name_max'),
        ]);
    }

    public function storeRoom(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(TableRules::room(), TableRules::messages(), TableRules::attributes());

        $room = $location->rooms()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $validated['name'],
            'is_outdoor' => $request->boolean('is_outdoor'),
        ]);

        $this->audit->log('room.created', $room, null, $validated);

        // room_id: Der Einrichtungsassistent legt danach den ersten Tisch in diesem Raum an.
        if ($request->wantsJson()) {
            return response()->json(['message' => __('Raum angelegt.'), 'room_id' => $room->id]);
        }

        return back()->with('success', __('Raum angelegt.'));
    }

    public function updateRoom(Request $request, Room $room): mixed
    {
        abort_if($room->location_id !== $this->context->locationId(), 404);

        $validated = $request->validate(TableRules::room(), TableRules::messages(), TableRules::attributes());

        $old = ['name' => $room->name, 'is_outdoor' => $room->is_outdoor];
        $room->update([
            'name' => $validated['name'],
            'is_outdoor' => $request->boolean('is_outdoor'),
        ]);

        $this->audit->log('room.updated', $room, $old, $validated);

        return $this->saved($request, __('Raum gespeichert.'));
    }

    public function deleteRoom(Request $request, Room $room): mixed
    {
        abort_if($room->location_id !== $this->context->locationId(), 404);

        // Ein Raum löscht seine Tische samt Verknüpfung zu Reservierungen in
        // der Datenbank mit. Solange Tische darin stehen, bleibt er. Die Sperre
        // auf dem Raum hält neue Tische fern, bis er gelöscht ist; ein zweiter
        // Löschauftrag (Doppelklick, zweites Fenster) findet ihn nicht mehr und
        // schreibt nichts ins Protokoll.
        $tableCount = 0;
        $deleted = null;
        DB::transaction(function () use ($room, &$tableCount, &$deleted): void {
            $locked = Room::whereKey($room->id)->where('location_id', $room->location_id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $tableCount = $locked->tables()->count();
            if ($tableCount > 0) {
                return;
            }

            $locked->delete();
            $deleted = $locked;
        });

        // Der Protokolleintrag folgt nach dem Festschreiben, weil sein Fremdschlüssel die Zeile des Betriebs sperrt, die
        // createWithinLimit vor dem Anlegen eines Tisches im Raum sperrt, und beide Anfragen einander sonst blockieren könnten.
        if ($deleted !== null) {
            $this->audit->log('room.deleted', $deleted, ['name' => $deleted->name]);
        }

        if ($tableCount > 0) {
            return $this->failed($request, 'room', trans_choice(
                '„:name“ enthält noch einen Tisch. Bitte zuerst den Tisch löschen.|„:name“ enthält noch :count Tische. Bitte zuerst die Tische löschen.',
                $tableCount,
                ['name' => $room->name, 'count' => $tableCount],
            ));
        }

        return $this->saved($request, __('Raum gelöscht.'));
    }

    public function storeTable(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $tenant = $location->tenant;

        // Ist der Tarif voll, nützt auch eine fehlerfreie Eingabe nichts: Die
        // Antwort kommt vor der Prüfung der Felder. Verbindlich zählt die
        // Prüfung unter der Sperre weiter unten.
        if (! $this->limits->canAdd($tenant, 'max_tables')) {
            return $this->failed($request, 'name', PlanLimitMessage::tables($tenant, $request->user()));
        }

        $validated = $request->validate(
            TableRules::table() + TableRules::tableFlags() + ['room_id' => ['required', 'integer']],
            TableRules::messages(),
            TableRules::attributes(),
        );

        if (! $location->rooms()->where('id', $validated['room_id'])->exists()) {
            return $this->failed($request, 'room_id', self::roomMissing());
        }

        [$width, $height] = RestaurantTable::sizeForCapacity('rect', (int) $validated['max_capacity']);

        // Der Raum wird unter der Sperre erneut gelesen, mit einer Lesesperre: Löscht ein zweites Fenster ihn
        // zwischen Prüfung und Anlegen, endet das nicht in einem Datenbankfehler, und ein Löschauftrag wartet
        // auf den neuen Tisch und findet dann einen Tisch im Raum.
        $roomGone = false;
        $table = $this->limits->createWithinLimit($tenant, 'max_tables', function () use ($location, $validated, $request, $width, $height, &$roomGone) {
            if ($location->rooms()->whereKey($validated['room_id'])->sharedLock()->first(['id']) === null) {
                $roomGone = true;

                return null;
            }

            return $location->tables()->create([
                'tenant_id' => $location->tenant_id,
                'room_id' => (int) $validated['room_id'],
                'name' => $validated['name'],
                'min_capacity' => (int) $validated['min_capacity'],
                'max_capacity' => (int) $validated['max_capacity'],
                'outdoor' => $request->boolean('outdoor'),
                'accessible' => $request->boolean('accessible'),
                'high_chair_possible' => $request->boolean('high_chair_possible'),
                // Ohne Angabe (Einrichtungsassistent) ist ein neuer Tisch kombinierbar und online buchbar.
                'joinable' => $request->boolean('joinable', true),
                'online_bookable' => $request->boolean('online_bookable', true),
                'width' => $width, 'height' => $height,
                'pos_x' => 50, 'pos_y' => 50,
            ]);
        });

        if ($roomGone) {
            return $this->failed($request, 'room_id', self::roomMissing());
        }

        if ($table === null) {
            return $this->failed($request, 'name', PlanLimitMessage::tables($tenant, $request->user()));
        }

        $this->audit->log('table.created', $table, null, $validated);

        return $this->saved($request, __('Tisch angelegt.'));
    }

    public function updateTable(Request $request, RestaurantTable $table): mixed
    {
        abort_if($table->location_id !== $this->context->locationId(), 404);

        $validated = $request->validate(
            TableRules::table() + TableRules::tableFlags(),
            TableRules::messages(),
            TableRules::attributes(),
        );

        // Beim Bearbeiten gilt nur, was das Formular mitschickt.
        $values = [
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
        ];
        foreach (TableRules::TABLE_FLAGS as $flag) {
            if ($request->has($flag)) {
                $values[$flag] = $request->boolean($flag);
            }
        }

        $old = $table->only(array_keys($values));
        $table->update($values);

        $this->audit->log('table.updated', $table, $old, $validated);

        return $this->saved($request, __('Tisch gespeichert.'));
    }

    public function deleteTable(Request $request, RestaurantTable $table): mixed
    {
        abort_if($table->location_id !== $this->context->locationId(), 404);

        // Eine kommende oder laufende Reservierung verlöre sonst still ihren Tisch: Ein gelöschter Tisch
        // verschwindet aus der Reservierung, auch aus Live-Board und Kasse. Die Sperre auf dem Tisch hält
        // zwischen Zählung und Löschen jede andere Änderung an seiner Zeile fern; ein zweiter Löschauftrag
        // (Doppelklick, zweites Fenster) findet ihn nicht mehr und schreibt nichts ins Protokoll.
        $reservations = 0;
        $deleted = null;
        DB::transaction(function () use ($table, &$reservations, &$deleted): void {
            $locked = RestaurantTable::whereKey($table->id)->where('location_id', $table->location_id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $reservations = $locked->reservations()
                ->where('reservations.end_at', '>=', now())
                ->whereIn('reservations.status', ReservationStatus::activeStatuses())
                ->count();
            if ($reservations > 0) {
                return;
            }

            $locked->delete();
            $deleted = $locked;
        });

        // Der Protokolleintrag folgt nach dem Festschreiben, wie bei deleteRoom: Sein Fremdschlüssel sperrt die Zeile des Betriebs.
        if ($deleted !== null) {
            $this->audit->log('table.deleted', $deleted);
        }

        if ($reservations > 0) {
            return $this->failed($request, 'table', trans_choice(
                '„:name“ hat noch eine kommende oder laufende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.|„:name“ hat noch :count kommende oder laufende Reservierungen. Bitte sie zuerst an andere Tische legen oder stornieren.',
                $reservations,
                ['name' => $table->name, 'count' => $reservations],
            ));
        }

        return $this->saved($request, __('Tisch gelöscht.'));
    }

    public function storeCombination(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(TableRules::combination(self::COMBINATION_CAPACITY_MAX), TableRules::messages(), TableRules::attributes());
        $tableIds = $this->joinableIds($location, $validated['table_ids']);
        $problem = $this->tablesProblem($validated['table_ids'], $tableIds);
        if ($problem !== null) {
            return $this->failed($request, 'table_ids', $problem);
        }

        $combination = DB::transaction(function () use ($location, $validated, $tableIds): TableCombination {
            $combination = $location->tableCombinations()->create([
                'tenant_id' => $location->tenant_id,
                'name' => $validated['name'],
                'min_capacity' => (int) $validated['min_capacity'],
                'max_capacity' => (int) $validated['max_capacity'],
            ]);
            $combination->tables()->sync($tableIds);

            return $combination;
        });

        $this->audit->log('table_combination.created', $combination, null, $validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Tischkombination angelegt.'), 'combination' => $this->combinationJson($combination)]);
        }

        return back()->with('success', __('Tischkombination angelegt.'));
    }

    public function updateCombination(Request $request, TableCombination $combination): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_if($combination->location_id !== $location->id, 404);

        $validated = $request->validate(TableRules::combination(self::COMBINATION_CAPACITY_MAX), TableRules::messages(), TableRules::attributes());
        // Ein Tisch, der schon zur Kombination gehört, darf bleiben, auch wenn er inzwischen nicht mehr
        // kombinierbar ist; neu hinzu kommt nur ein kombinierbarer Tisch.
        $tableIds = $this->joinableIds($location, $validated['table_ids'], $combination->tables()->pluck('restaurant_tables.id'));
        $problem = $this->tablesProblem($validated['table_ids'], $tableIds);
        if ($problem !== null) {
            return $this->failed($request, 'table_ids', $problem);
        }

        DB::transaction(function () use ($combination, $validated, $tableIds): void {
            $combination->update([
                'name' => $validated['name'],
                'min_capacity' => (int) $validated['min_capacity'],
                'max_capacity' => (int) $validated['max_capacity'],
            ]);
            $combination->tables()->sync($tableIds);
        });

        $this->audit->log('table_combination.updated', $combination, null, $validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Tischkombination aktualisiert.'), 'combination' => $this->combinationJson($combination)]);
        }

        return back()->with('success', __('Tischkombination aktualisiert.'));
    }

    public function deleteCombination(Request $request, TableCombination $combination): mixed
    {
        abort_if($combination->location_id !== $this->context->locationId(), 404);

        // Ein zweiter Löschauftrag trifft keine Zeile mehr und schreibt nichts ins Protokoll.
        DB::transaction(function () use ($combination): void {
            $deleted = TableCombination::whereKey($combination->id)->where('location_id', $combination->location_id)->delete();
            if ($deleted === 1) {
                $this->audit->log('table_combination.deleted', $combination, ['name' => $combination->name]);
            }
        });

        return $this->saved($request, __('Tischkombination gelöscht.'));
    }

    /**
     * Nach Reihenfolge und Name sortiert, "T2" vor "T10".
     *
     * @template TKey of array-key
     * @template TModel of Room|RestaurantTable|TableCombination
     *
     * @param  Collection<TKey, TModel>  $items
     * @return Collection<int, TModel>
     */
    private function inOrder(Collection $items): Collection
    {
        return $items
            ->sort(fn ($a, $b) => (($a->sort_order ?? 0) <=> ($b->sort_order ?? 0)) ?: strnatcasecmp((string) $a->name, (string) $b->name))
            ->values();
    }

    /**
     * Nur kombinierbare Tische dieses Standorts und die Tische aus $keep (die
     * bisherigen Mitglieder einer Kombination); fremde IDs fallen weg.
     *
     * @param  array<int, mixed>  $ids
     * @param  Collection<int, int>|null  $keep
     * @return Collection<int, int>
     */
    private function joinableIds(Location $location, array $ids, ?Collection $keep = null): Collection
    {
        return $location->tables()
            ->whereIn('id', $ids)
            ->where(fn ($query) => $query->where('joinable', true)->orWhereIn('id', $keep ?? []))
            ->pluck('id');
    }

    private static function roomMissing(): string
    {
        return __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.');
    }

    /**
     * Grund, warum die gewählten Tische keine Kombination ergeben, oder null.
     * Ein Tisch, der nicht zu diesem Standort gehört oder nicht kombinierbar
     * ist, lehnt die Eingabe ab; sonst entstünde eine Kombination mit anderen
     * Tischen, als die Person gewählt hat.
     *
     * @param  array<int, mixed>  $requested
     * @param  Collection<int, int>  $valid
     */
    private function tablesProblem(array $requested, Collection $valid): ?string
    {
        if ($valid->count() < 2) {
            return __('Eine Kombination braucht mindestens zwei Tische mit „Kombinierbar“. Bitte zwei solche Tische wählen oder bei einem Tisch „Kombinierbar“ einschalten.');
        }

        if ($valid->count() < count(array_unique(array_map('intval', $requested)))) {
            return __('Mindestens ein gewählter Tisch gehört nicht zu diesem Standort oder ist nicht mehr kombinierbar. Bitte die Seite neu laden und die Tische neu wählen.');
        }

        return null;
    }

    /**
     * Form, die der Tischplan erwartet.
     *
     * @return array<string, mixed>
     */
    private function combinationJson(TableCombination $combination): array
    {
        $combination->load('tables:id,name');

        return [
            'id' => $combination->id,
            'name' => $combination->name,
            'min_capacity' => $combination->min_capacity,
            'max_capacity' => $combination->max_capacity,
            'tables' => $combination->tables->map(fn (RestaurantTable $table) => ['id' => $table->id, 'name' => $table->name])->values(),
        ];
    }
}

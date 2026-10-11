<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\BlackoutPeriod;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\SpecialOpeningHour;
use App\Models\TableBlock;
use App\Support\OpeningHoursPreview;
use App\Support\ScheduleExceptionRules;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ausnahmen (Einstellungen › Platz & Zeiten › Ausnahmen): alles, was an
 * bestimmten Tagen anders ist. Eine Ausnahme liegt in den bestehenden Tabellen:
 *
 * - geschlossen, andere Öffnungszeiten: special_opening_hours, je Datum eine Zeile (je Fenster eine)
 * - weniger Gäste, Raum gesperrt: blackout_periods
 * - Tisch gesperrt: table_blocks
 *
 * Alle Zeilen einer Ausnahme tragen dieselbe exception_group und erscheinen als
 * ein Eintrag mit dem Schlüssel "g-{uuid}". Zeilen ohne Gruppe stammen aus der
 * Zeit davor und erscheinen einzeln: "s-{id}" (Sondertag), "b-{id}" (Sperrzeit),
 * "t-{id}" (Tischsperre). Speichern ersetzt alle Zeilen eines Eintrags in einer
 * Transaktion.
 *
 * Sperrzeiten und Tischsperren liegen in UTC, die Eingaben in der Zeit des
 * Standorts. Die Grenzen stehen in config/swayy.php (exceptions.*).
 *
 * @phpstan-type Bundle array{special: Collection<int, SpecialOpeningHour>, blackouts: Collection<int, BlackoutPeriod>, blocks: Collection<int, TableBlock>}
 * @phpstan-type Names array{rooms: array<int, string>, tables: array<int, array{name: string, deleted: bool}>}
 * @phpstan-type Entry array{key: string, from: string, until: string, label: ?string, effects: list<array{type: string, text: string}>, editable: bool, note: ?string, form: array<string, mixed>}
 */
final class ScheduleExceptions
{
    public function __construct(
        private readonly TimeSlotService $slots,
        private readonly OpeningHoursPreview $preview,
    ) {}

    /**
     * Die Einträge des Standorts: kommende nach Monat des ersten Tages
     * gruppiert (Schlüssel "Oktober 2026", aufsteigend), vergangene absteigend,
     * höchstens exceptions.past_months Monate zurück. Ein Eintrag, der schon
     * läuft, steht im Monat von heute.
     *
     * @return array{upcoming: array<string, list<Entry>>, past: list<Entry>}
     */
    public function entries(Location $location): array
    {
        $today = CarbonImmutable::now($location->timezone)->startOfDay();
        $cutoff = $today->subMonthsNoOverflow((int) config('swayy.exceptions.past_months'));
        $names = $this->names($location);

        $upcoming = [];
        $past = [];
        foreach ($this->bundles($location, $cutoff) as $key => $bundle) {
            $entry = $this->entry($location, $key, $bundle, $names);
            if ($entry['until'] < $today->toDateString()) {
                $past[] = $entry;
            } else {
                $upcoming[] = [max($entry['from'], $today->toDateString()), $entry];
            }
        }

        usort($upcoming, fn (array $a, array $b) => [$a[0], $a[1]['from'], $a[1]['until'], $a[1]['key']] <=> [$b[0], $b[1]['from'], $b[1]['until'], $b[1]['key']]);
        usort($past, fn (array $a, array $b) => [$b['from'], $b['until'], $b['key']] <=> [$a['from'], $a['until'], $a['key']]);

        $months = [];
        foreach ($upcoming as [$start, $entry]) {
            $months[CarbonImmutable::parse($start)->locale('de')->translatedFormat('F Y')][] = $entry;
        }

        return ['upcoming' => $months, 'past' => $past];
    }

    /**
     * Der Eintrag zu einem Schlüssel, oder null, wenn der Standort keinen hat.
     *
     * @return ?Entry
     */
    public function find(Location $location, string $key): ?array
    {
        $bundle = $this->bundle($location, $key);

        return $bundle === null ? null : $this->entry($location, $key, $bundle, $this->names($location));
    }

    /**
     * Legt eine Ausnahme an ($key null) oder ersetzt alle Zeilen des Eintrags
     * $key. Die Daten sind geprüft (ScheduleExceptionRules). Eine Tischsperre
     * über einer aktiven Reservierung dieses Tisches lehnt die Sperre mit
     * einer ValidationException am Feld lock_tables ab; ein unbekannter
     * Schlüssel ergibt 404.
     *
     * overlapping zählt die aktiven Reservierungen, die in den Zeitraum fallen
     * und bestehen bleiben.
     *
     * @param  array<string, mixed>  $data
     * @return array{entry: Entry, overlapping: int}
     */
    public function save(Location $location, array $data, ?string $key, ?int $userId): array
    {
        $effects = array_values(array_intersect(ScheduleExceptionRules::EFFECTS, (array) ($data['effects'] ?? [])));

        return DB::transaction(function () use ($location, $data, $key, $userId, $effects) {
            $this->lock($location);

            $group = (string) Str::uuid();
            if ($key !== null) {
                $existing = $this->bundle($location, $key, lock: true);
                abort_if($existing === null, 404);
                $group = str_starts_with($key, 'g-') ? strtolower(substr($key, 2)) : $group;
                $this->deleteBundle($existing);
            }

            $this->write($location, $data, $effects, $group, $userId);

            $entry = $this->find($location, 'g-'.$group);
            abort_if($entry === null, 404);

            return ['entry' => $entry, 'overlapping' => $this->overlapping($location, $data, $effects)];
        });
    }

    /**
     * Löscht alle Zeilen des Eintrags. false, wenn der Standort den Schlüssel nicht hat.
     */
    public function delete(Location $location, string $key): bool
    {
        return DB::transaction(function () use ($location, $key) {
            $this->lock($location);

            $bundle = $this->bundle($location, $key, lock: true);
            if ($bundle === null) {
                return false;
            }
            $this->deleteBundle($bundle);

            return true;
        });
    }

    /**
     * Ein Satz, der beschreibt, was Gäste erleben, wenn die Ausnahme gilt
     * (Vorschau über den Knöpfen). Die letzte Uhrzeit rechnet mit Takt und
     * Dauer des Standorts wie die Buchungsseite; gilt keine Ausnahme mit
     * eigenen Öffnungszeiten, zählen die regulären Zeiten des ersten Tages.
     *
     * @param  array<string, mixed>  $data  geprüfte Felder aus ScheduleExceptionRules::rules()
     */
    public function describe(Location $location, array $data): string
    {
        $effects = array_values(array_intersect(ScheduleExceptionRules::EFFECTS, (array) ($data['effects'] ?? [])));
        if ($effects === []) {
            return '';
        }

        $salon = (bool) $location->tenant?->isSalon();
        $from = (string) $data['from'];
        $until = (string) ($data['until'] ?? $from);
        $when = $from === $until
            ? __('Am :date', ['date' => $this->date($from)])
            : __('Vom :from bis :until', ['from' => $this->date($from), 'until' => $this->date($until)]);

        if ($effects === ['closed']) {
            return __(':when kann niemand online buchen.', ['when' => $when]);
        }

        $windows = in_array('hours', $effects, true)
            ? $this->slots->windowsFromTimes(CarbonImmutable::parse($from, $location->timezone), $this->sortedWindows($data))
            : $this->regularWindows($location, CarbonImmutable::parse($from, $location->timezone));
        $last = $this->lastStart($location, $windows, $salon);
        $guests = $salon ? __('Kunden') : __('Gäste');
        $noTime = $salon
            ? __('Kein Fenster ist lang genug für einen Termin.')
            : __('Kein Fenster ist lang genug für einen Besuch.');

        if ($effects === ['hours']) {
            $opening = __(':when buchen :guests zwischen :open und :close', [
                'when' => $when, 'guests' => $guests, 'open' => $this->firstOpening($windows), 'close' => $this->lastClosing($windows),
            ]);

            return $last === null
                ? $opening.', '.__('finden aber keine Uhrzeit: :reason', ['reason' => $noTime])
                : $opening.', '.__('letzte Uhrzeit :last.', ['last' => $last]);
        }

        $parts = [];
        if (in_array('hours', $effects, true)) {
            $parts[] = __('buchen :guests zwischen :open und :close', ['guests' => $guests, 'open' => $this->firstOpening($windows), 'close' => $this->lastClosing($windows)]);
        }
        if (in_array('limit', $effects, true)) {
            $parts[] = $this->limitPart($location, $data, $salon, first: $parts === []);
        }
        if (in_array('lock', $effects, true)) {
            $parts[] = $this->lockPart($location, $data, first: $parts === []);
        }
        $parts = array_values(array_filter($parts));
        $sentence = $when.' '.implode('; ', $parts);

        if ($last !== null) {
            return $sentence.'; '.__('letzte Uhrzeit :last.', ['last' => $last]);
        }

        return $windows === []
            ? $sentence.'.'
            : $sentence.'; '.__(':guests finden keine Uhrzeit: :reason', ['guests' => $guests, 'reason' => $noTime]);
    }

    // --- Lesen --------------------------------------------------------------

    /**
     * Die Zeilen der letzten Monate, nach Schlüssel gebündelt. Eine Gruppe mit
     * einer Zeile im Zeitraum kommt vollständig.
     *
     * @return array<string, Bundle>
     */
    private function bundles(Location $location, CarbonImmutable $cutoff): array
    {
        $date = $cutoff->toDateString();
        $utc = $cutoff->utc();
        $windows = [
            SpecialOpeningHour::class => fn (Builder $query) => $query->where('date', '>=', $date),
            BlackoutPeriod::class => fn (Builder $query) => $query->where('ends_at', '>', $utc),
            TableBlock::class => fn (Builder $query) => $query->where('ends_at', '>', $utc),
        ];

        $groups = [];
        foreach ($windows as $model => $window) {
            $groups = array_merge($groups, $model::query()
                ->where('location_id', $location->id)->whereNotNull('exception_group')->where($window)
                ->distinct()->pluck('exception_group')->all());
        }
        $groups = array_values(array_unique($groups));

        $bundles = [];
        foreach ($this->slotsOfRows() as [$slot, $model, $prefix]) {
            $rows = $model::query()->where('location_id', $location->id)
                ->where(fn (Builder $query) => $query->where($windows[$model])->orWhereIn('exception_group', $groups))
                ->get();
            foreach ($rows as $row) {
                $key = $row->exception_group !== null ? 'g-'.$row->exception_group : $prefix.'-'.$row->id;
                $bundles[$key] ??= $this->emptyBundle();
                $bundles[$key][$slot]->push($row);
            }
        }

        return $bundles;
    }

    /**
     * Alle Zeilen eines Schlüssels, oder null ohne Treffer. Mit $lock sperrt
     * die Abfrage die Zeilen bis zum Ende der Transaktion.
     *
     * @return ?Bundle
     */
    private function bundle(Location $location, string $key, bool $lock = false): ?array
    {
        if (preg_match('/^([gsbt])-(.+)$/', $key, $match) !== 1) {
            return null;
        }
        [, $kind, $value] = $match;

        if ($kind === 'g') {
            // Die Spalte ist ein UUID-Feld; unter PostgreSQL löst jeder andere Wert einen Datenbankfehler aus.
            // Ein Schlüssel ohne UUID heißt deshalb: nichts gefunden.
            if (! Str::isUuid($value)) {
                return null;
            }
            $value = strtolower($value);
        } elseif (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            return null;
        }

        $bundle = $this->emptyBundle();
        foreach ($this->slotsOfRows() as [$slot, $model, $prefix]) {
            if ($kind !== 'g' && $kind !== $prefix) {
                continue;
            }
            $query = $model::query()->where('location_id', $location->id);
            if ($kind === 'g') {
                $query->where('exception_group', $value);
            } else {
                $query->whereNull('exception_group')->whereKey((int) $value);
            }
            $bundle[$slot] = $lock ? $query->lockForUpdate()->get() : $query->get();
        }

        return $bundle['special']->isEmpty() && $bundle['blackouts']->isEmpty() && $bundle['blocks']->isEmpty() ? null : $bundle;
    }

    /**
     * Spalte im Bündel, Modell und Präfix des Schlüssels für die drei Tabellen.
     *
     * @return list<array{0: string, 1: class-string<Model>, 2: string}>
     */
    private function slotsOfRows(): array
    {
        return [
            ['special', SpecialOpeningHour::class, 's'],
            ['blackouts', BlackoutPeriod::class, 'b'],
            ['blocks', TableBlock::class, 't'],
        ];
    }

    /** @return Bundle */
    private function emptyBundle(): array
    {
        return ['special' => new Collection, 'blackouts' => new Collection, 'blocks' => new Collection];
    }

    /**
     * Namen der Räume und Tische des Standorts. Gelöschte Tische bleiben mit
     * ihrem Namen lesbar, die Formulare bieten sie nicht mehr an.
     *
     * @return Names
     */
    private function names(Location $location): array
    {
        $tables = [];
        foreach (RestaurantTable::withTrashed()->where('location_id', $location->id)->get(['id', 'name', 'deleted_at']) as $table) {
            $tables[(int) $table->id] = ['name' => (string) $table->name, 'deleted' => $table->trashed()];
        }

        return [
            'rooms' => $location->rooms()->pluck('name', 'id')->map(fn ($name) => (string) $name)->all(),
            'tables' => $tables,
        ];
    }

    // --- Eintrag aufbauen -----------------------------------------------------

    /**
     * @param  Bundle  $bundle
     * @param  Names  $names
     * @return Entry
     */
    private function entry(Location $location, string $key, array $bundle, array $names): array
    {
        $tz = $location->timezone;
        $salon = (bool) $location->tenant?->isSalon();

        $special = $bundle['special']->sortBy(fn (SpecialOpeningHour $row) => $row->date->toDateString().' '.$row->opens_at)->values();
        $spans = $bundle['blackouts']->concat($bundle['blocks'])->sortBy(fn (Model $row) => $row->starts_at->getTimestamp())->values();
        $limits = $bundle['blackouts']->filter(fn (BlackoutPeriod $row) => $row->reduce_covers_to !== null)->sortBy(fn (BlackoutPeriod $row) => $row->starts_at->getTimestamp())->values();
        $locks = $bundle['blackouts']->filter(fn (BlackoutPeriod $row) => $row->reduce_covers_to === null)->sortBy(fn (BlackoutPeriod $row) => $row->starts_at->getTimestamp())->values();
        $blocks = $bundle['blocks']->sortBy(fn (TableBlock $row) => $row->starts_at->getTimestamp())->values();

        $days = [];
        foreach ($special as $row) {
            $days[] = $row->date->toDateString();
        }
        foreach ($spans as $row) {
            [$first, $last] = $this->days($row, $tz);
            array_push($days, $first, $last);
        }
        sort($days);
        $from = $days[0];
        $until = $days[array_key_last($days)];

        $closed = $special->contains(fn (SpecialOpeningHour $row) => $row->closed);
        $open = $special->filter(fn (SpecialOpeningHour $row) => ! $row->closed && $row->opens_at !== null && $row->closes_at !== null)->values();
        $firstDay = $open->isEmpty() ? null : $open->first()->date->toDateString();
        $windows = $firstDay === null ? [] : $open
            ->filter(fn (SpecialOpeningHour $row) => $row->date->toDateString() === $firstDay)
            ->map(fn (SpecialOpeningHour $row) => ['opens_at' => substr((string) $row->opens_at, 0, 5), 'closes_at' => substr((string) $row->closes_at, 0, 5)])
            ->values()->all();

        $effects = [];
        $form = [
            'from' => $from, 'until' => $until === $from ? null : $until, 'label' => $this->label($bundle),
            'effects' => [], 'windows' => $windows,
            'limit_covers' => null, 'limit_room_id' => null, 'limit_from' => null, 'limit_until' => null,
            'lock_rooms' => [], 'lock_tables' => [], 'lock_from' => null, 'lock_until' => null,
        ];

        if ($closed) {
            $effects[] = ['type' => 'closed', 'text' => __('Geschlossen')];
            $form['effects'][] = 'closed';
        }
        if ($windows !== []) {
            $effects[] = ['type' => 'hours', 'text' => __('Geöffnet :windows', ['windows' => $this->joinList(array_map(fn (array $w) => $w['opens_at'].'–'.$w['closes_at'], $windows))])];
            $form['effects'][] = 'hours';
        }
        if ($limits->isNotEmpty()) {
            /** @var BlackoutPeriod $limit */
            $limit = $limits->first();
            $times = $this->times($limit, $tz);
            $covers = (int) $limit->reduce_covers_to;
            $guests = trans_choice($salon ? '{1} :count Kunde|[0,*] :count Kunden' : '{1} :count Gast|[0,*] :count Gäste', $covers);
            $where = $limit->room_id === null
                ? __('ganzer Betrieb')
                : __('Raum „:room“', ['room' => $names['rooms'][(int) $limit->room_id] ?? __('gelöschter Raum')]);
            $effects[] = ['type' => 'limit', 'text' => __('Höchstens :guests gleichzeitig (:where)', ['guests' => $guests, 'where' => $where]).($times === null ? '' : ', '.$times[0].'–'.$times[1])];
            $form['effects'][] = 'limit';
            $form['limit_covers'] = $covers;
            $form['limit_room_id'] = $limit->room_id === null ? null : (int) $limit->room_id;
            [$form['limit_from'], $form['limit_until']] = $times ?? [null, null];
        }
        if ($locks->isNotEmpty() || $blocks->isNotEmpty()) {
            $wholeBusiness = $locks->contains(fn (BlackoutPeriod $row) => $row->room_id === null);
            $roomIds = $locks->filter(fn (BlackoutPeriod $row) => $row->room_id !== null)->map(fn (BlackoutPeriod $row) => (int) $row->room_id)->unique()->values();
            $tableIds = $blocks->map(fn (TableBlock $row) => (int) $row->restaurant_table_id)->unique()->values();
            $first = $locks->isNotEmpty() ? $locks->first() : $blocks->first();
            $times = $this->times($first, $tz);

            if ($wholeBusiness) {
                $text = __('Ganzer Betrieb gesperrt');
            } else {
                $items = [];
                foreach ($roomIds as $id) {
                    $items[] = $this->quoted($names['rooms'][$id] ?? __('gelöschter Raum'));
                }
                foreach ($tableIds as $id) {
                    $items[] = __('Tisch :table', ['table' => $names['tables'][$id]['name'] ?? '?']);
                }
                $text = __('Gesperrt: :items', ['items' => implode(', ', $items)]);
            }
            $effects[] = ['type' => 'lock', 'text' => $text.($times === null ? '' : ', '.$times[0].'–'.$times[1])];
            $form['effects'][] = 'lock';
            $form['lock_rooms'] = $roomIds->filter(fn (int $id) => isset($names['rooms'][$id]))->values()->all();
            $form['lock_tables'] = $tableIds->filter(fn (int $id) => isset($names['tables'][$id]) && ! $names['tables'][$id]['deleted'])->values()->all();
            [$form['lock_from'], $form['lock_until']] = $times ?? [null, null];
        }

        [$editable, $note] = $this->editable($key, $bundle, $tz);

        return [
            'key' => $key, 'from' => $from, 'until' => $until, 'label' => $form['label'],
            'effects' => $effects, 'editable' => $editable, 'note' => $note, 'form' => $form,
        ];
    }

    /**
     * Lässt sich der Eintrag im Formular bearbeiten? Gruppen und Sondertage
     * immer. Eine ältere einzelne Sperre nur, wenn sie ganze Tage umfasst oder
     * an einem Tag beginnt und vor ihrem Beginn am Folgetag endet; eine
     * Sperre des ganzen Betriebs gibt es im Formular nicht. Sonst nennt die
     * Notiz den Grund.
     *
     * @param  Bundle  $bundle
     * @return array{0: bool, 1: ?string}
     */
    private function editable(string $key, array $bundle, string $tz): array
    {
        if (str_starts_with($key, 'g-') || str_starts_with($key, 's-')) {
            return [true, null];
        }

        /** @var BlackoutPeriod|TableBlock $row */
        $row = $bundle['blackouts']->first() ?? $bundle['blocks']->first();
        if (! $this->representable($row, $tz)) {
            return [false, __('Diese ältere Sperre läuft durchgehend vom :from bis :until. Zum Ändern bitte löschen und neu anlegen.', [
                'from' => $this->local($row->starts_at, $tz)->format('d.m.Y, H:i'),
                'until' => $this->local($row->ends_at, $tz)->format('d.m.Y, H:i'),
            ])];
        }
        if ($row instanceof BlackoutPeriod && $row->reduce_covers_to === null && $row->room_id === null) {
            return [false, __('Diese ältere Sperre schließt den ganzen Betrieb. Zum Ändern bitte löschen und als „Geschlossen“ oder „Andere Öffnungszeiten“ neu anlegen.')];
        }

        return [true, null];
    }

    /** @param Bundle $bundle */
    private function label(array $bundle): ?string
    {
        $labels = $bundle['special']->pluck('label')
            ->concat($bundle['blackouts']->pluck('reason'))
            ->concat($bundle['blocks']->pluck('reason'))
            ->filter(fn ($label) => is_string($label) && $label !== '');

        return $labels->isEmpty() ? null : (string) $labels->first();
    }

    // --- Zeiträume --------------------------------------------------------------

    /**
     * Die Tage, die eine Sperrzeile umfasst (Standortzeit): bei Uhrzeiten der
     * Tag ihres Beginns, bei ganzen Tagen vom ersten bis zum letzten Tag.
     *
     * @return array{0: string, 1: string}
     */
    private function days(Model $row, string $tz): array
    {
        $start = $this->local($row->starts_at, $tz);
        if ($this->times($row, $tz) !== null) {
            return [$start->toDateString(), $start->toDateString()];
        }

        return [$start->toDateString(), $this->local($row->ends_at, $tz)->subSecond()->toDateString()];
    }

    /**
     * Beginn und Ende als "HH:MM", wenn die Zeile ein Uhrzeitfenster eines
     * Tages ist (am selben Tag oder über Mitternacht bis vor dem Beginn);
     * null bei ganzen Tagen und bei durchgehenden Zeiträumen.
     *
     * @return ?array{0: string, 1: string}
     */
    private function times(Model $row, string $tz): ?array
    {
        $start = $this->local($row->starts_at, $tz);
        $end = $this->local($row->ends_at, $tz);

        if ($start->format('H:i') === '00:00' && $end->format('H:i') === '00:00') {
            return null;
        }
        $sameDay = $start->toDateString() === $end->toDateString() && $end->gt($start);
        $nextDay = $end->toDateString() === $start->addDay()->toDateString() && $end->format('H:i') < $start->format('H:i');

        return $sameDay || $nextDay ? [$start->format('H:i'), $end->format('H:i')] : null;
    }

    /** Lässt sich der Zeitraum der Zeile mit den Feldern des Formulars darstellen? */
    private function representable(Model $row, string $tz): bool
    {
        $start = $this->local($row->starts_at, $tz);
        $end = $this->local($row->ends_at, $tz);

        return ($start->format('H:i') === '00:00' && $end->format('H:i') === '00:00') || $this->times($row, $tz) !== null;
    }

    private function local(DateTimeInterface $moment, string $tz): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($tz);
    }

    /**
     * Zeitspannen (UTC) einer Wirkung. Ohne Uhrzeiten eine Spanne über alle
     * Tage, mit Uhrzeiten je Tag eine; liegt das Ende nicht nach dem Beginn,
     * reicht die Spanne in den Folgetag.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function spans(Location $location, array $data, string $prefix): array
    {
        $tz = $location->timezone;
        $from = (string) $data['from'];
        $until = (string) ($data['until'] ?? $from);
        $start = $data["{$prefix}_from"] ?? null;
        $end = $data["{$prefix}_until"] ?? null;

        if ($start === null || $end === null) {
            return [[
                CarbonImmutable::parse($from.' 00:00', $tz)->utc(),
                CarbonImmutable::parse($until.' 00:00', $tz)->addDay()->utc(),
            ]];
        }

        $spans = [];
        foreach ($this->dates($data) as $day) {
            $endDay = $end > $start ? $day : CarbonImmutable::parse($day, $tz)->addDay()->toDateString();
            $spans[] = [CarbonImmutable::parse("$day $start", $tz)->utc(), CarbonImmutable::parse("$endDay $end", $tz)->utc()];
        }

        return $spans;
    }

    /**
     * Die Tage von "from" bis "until" (ohne "until" nur "from"), als "Y-m-d".
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function dates(array $data): array
    {
        $day = CarbonImmutable::parse((string) $data['from'], 'UTC');
        $last = CarbonImmutable::parse((string) ($data['until'] ?? $data['from']), 'UTC');

        $dates = [];
        for (; $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    // --- Schreiben --------------------------------------------------------------

    /**
     * Zwei gleichzeitige Speicherungen eines Standorts laufen nacheinander:
     * Die zweite sieht danach die Zeilen der ersten und ersetzt sie.
     * Allein die Sperre auf den Zeilen der Gruppe reicht dafür nicht, denn sie
     * läuft ins Leere, sobald die erste Speicherung diese Zeilen gelöscht hat.
     */
    private function lock(Location $location): void
    {
        Location::query()->whereKey($location->id)->lockForUpdate()->first();
    }

    /**
     * @param  Bundle  $bundle
     */
    private function deleteBundle(array $bundle): void
    {
        foreach ($this->slotsOfRows() as [$slot, $model]) {
            if ($bundle[$slot]->isNotEmpty()) {
                $model::query()->whereKey($bundle[$slot]->pluck('id')->all())->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $effects
     */
    private function write(Location $location, array $data, array $effects, string $group, ?int $userId): void
    {
        $base = ['tenant_id' => $location->tenant_id, 'location_id' => $location->id, 'exception_group' => $group];
        $label = $data['label'] ?? null;
        $label = is_string($label) && $label !== '' ? $label : null;

        if (in_array('closed', $effects, true)) {
            foreach ($this->dates($data) as $day) {
                SpecialOpeningHour::create($base + ['date' => $day, 'closed' => true, 'label' => $label]);
            }
        }

        if (in_array('hours', $effects, true)) {
            foreach ($this->dates($data) as $day) {
                foreach ($this->sortedWindows($data) as $window) {
                    SpecialOpeningHour::create($base + [
                        'date' => $day, 'closed' => false, 'label' => $label,
                        'opens_at' => $window['opens_at'], 'closes_at' => $window['closes_at'],
                    ]);
                }
            }
        }

        if (in_array('limit', $effects, true)) {
            $roomId = ($data['limit_room_id'] ?? null) === null ? null : (int) $data['limit_room_id'];
            foreach ($this->spans($location, $data, 'limit') as [$start, $end]) {
                BlackoutPeriod::create($base + [
                    'room_id' => $roomId, 'starts_at' => $start, 'ends_at' => $end,
                    'reduce_covers_to' => (int) $data['limit_covers'], 'reason' => $label,
                ]);
            }
        }

        if (in_array('lock', $effects, true)) {
            $spans = $this->spans($location, $data, 'lock');
            $tableIds = $this->ids($data['lock_tables'] ?? null);
            $this->assertTablesFree($location, $tableIds, $spans);

            foreach ($this->ids($data['lock_rooms'] ?? null) as $roomId) {
                foreach ($spans as [$start, $end]) {
                    BlackoutPeriod::create($base + [
                        'room_id' => $roomId, 'starts_at' => $start, 'ends_at' => $end,
                        'reduce_covers_to' => null, 'reason' => $label,
                    ]);
                }
            }
            foreach ($tableIds as $tableId) {
                foreach ($spans as [$start, $end]) {
                    TableBlock::create($base + [
                        'restaurant_table_id' => $tableId, 'starts_at' => $start, 'ends_at' => $end,
                        'reason' => $label, 'created_by' => $userId,
                    ]);
                }
            }
        }
    }

    /**
     * Eine Tischsperre betrifft die künftige Vergabe des Tisches. Eine
     * Reservierung darüber bliebe bestehen und säße unbemerkt auf einem
     * gesperrten Tisch; deshalb lehnt die Sperre ab, solange eine aktive
     * Reservierung im Zeitraum auf dem Tisch liegt. Die Prüfung läuft in der
     * Transaktion des Speicherns.
     *
     * @param  list<int>  $tableIds
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $spans
     */
    private function assertTablesFree(Location $location, array $tableIds, array $spans): void
    {
        if ($tableIds === []) {
            return;
        }

        foreach ($spans as [$start, $end]) {
            $reservation = Reservation::query()
                ->where('location_id', $location->id)
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->where('start_at', '<', $end)
                ->where('end_at', '>', $start)
                ->whereHas('tables', fn (Builder $query) => $query->whereIn('restaurant_tables.id', $tableIds))
                ->with(['tables' => fn ($query) => $query->whereIn('restaurant_tables.id', $tableIds)])
                ->orderBy('start_at')
                ->first();

            if ($reservation !== null) {
                $begin = $this->local($reservation->start_at, $location->timezone);

                throw ValidationException::withMessages(['lock_tables' => __('Am :date um :time liegt eine Reservierung auf Tisch :table. Bitte zuerst umbelegen oder stornieren.', [
                    'date' => $begin->format('d.m.Y'),
                    'time' => $begin->format('H:i'),
                    'table' => (string) $reservation->tables->first()?->name,
                ])]);
            }
        }
    }

    /**
     * Zahl der aktiven Reservierungen des Standorts, die in den Zeitraum der
     * Ausnahme fallen: ganze Tage bei "Geschlossen" und "Andere
     * Öffnungszeiten", die Spannen bei Grenze und Sperre.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $effects
     */
    private function overlapping(Location $location, array $data, array $effects): int
    {
        $spans = [];
        if (array_intersect($effects, ['closed', 'hours']) !== []) {
            $spans = array_merge($spans, $this->spans($location, ['from' => $data['from'], 'until' => $data['until'] ?? null], 'none'));
        }
        foreach (['limit', 'lock'] as $effect) {
            if (in_array($effect, $effects, true)) {
                $spans = array_merge($spans, $this->spans($location, $data, $effect));
            }
        }
        if ($spans === []) {
            return 0;
        }

        return Reservation::query()
            ->where('location_id', $location->id)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where(function (Builder $query) use ($spans) {
                foreach ($this->merged($spans) as [$start, $end]) {
                    $query->orWhere(fn (Builder $span) => $span->where('start_at', '<', $end)->where('end_at', '>', $start));
                }
            })
            ->count();
    }

    /**
     * Zeitspannen nach Beginn sortiert, sich berührende zusammengefasst.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $spans
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function merged(array $spans): array
    {
        usort($spans, fn (array $a, array $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $merged = [];
        foreach ($spans as $span) {
            $last = array_key_last($merged);
            if ($last !== null && $span[0]->lte($merged[$last][1])) {
                if ($span[1]->gt($merged[$last][1])) {
                    $merged[$last][1] = $span[1];
                }

                continue;
            }
            $merged[] = $span;
        }

        return $merged;
    }

    /**
     * Die eindeutigen Nummern aus einer Auswahlliste.
     *
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $value, 'is_scalar'))));
    }

    // --- Satz der Vorschau --------------------------------------------------------

    /**
     * Die Zeitfenster aus den Feldern, nach Beginn sortiert.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{opens_at: string, closes_at: string}>
     */
    private function sortedWindows(array $data): array
    {
        $windows = [];
        foreach ((array) ($data['windows'] ?? []) as $window) {
            if (is_array($window) && isset($window['opens_at'], $window['closes_at'])) {
                $windows[] = ['opens_at' => (string) $window['opens_at'], 'closes_at' => (string) $window['closes_at']];
            }
        }
        usort($windows, fn (array $a, array $b) => $a['opens_at'] <=> $b['opens_at']);

        return $windows;
    }

    /**
     * Die regulären Zeitfenster des Wochentags von $day.
     *
     * @return array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>
     */
    private function regularWindows(Location $location, CarbonImmutable $day): array
    {
        $times = $location->openingHours()->where('weekday', $day->dayOfWeekIso - 1)->orderBy('opens_at')->get()
            ->map(fn ($hour) => ['opens_at' => (string) $hour->opens_at, 'closes_at' => (string) $hour->closes_at])->all();

        return $this->slots->windowsFromTimes($day, $times);
    }

    /**
     * Die letzte Startzeit der Fenster ("HH:MM"), oder null, wenn keine Uhrzeit bleibt.
     *
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function lastStart(Location $location, array $windows, bool $salon): ?string
    {
        $starts = $this->slots->startsForWindows($windows, $this->preview->duration($location, $salon), $this->slots->slotInterval($location));

        return $starts === [] ? null : $starts[array_key_last($starts)]->format('H:i');
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function firstOpening(array $windows): string
    {
        return collect($windows)->map(fn (array $window) => $window['opens'])->sortBy(fn (CarbonImmutable $time) => $time->getTimestamp())->first()?->format('H:i') ?? '';
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function lastClosing(array $windows): string
    {
        return collect($windows)->map(fn (array $window) => $window['closes'])->sortByDesc(fn (CarbonImmutable $time) => $time->getTimestamp())->first()?->format('H:i') ?? '';
    }

    /** @param array<string, mixed> $data */
    private function limitPart(Location $location, array $data, bool $salon, bool $first): string
    {
        $covers = (int) ($data['limit_covers'] ?? 0);
        $guests = trans_choice($salon ? '{1} :count Kunde|[0,*] :count Kunden' : '{1} :count Gast|[0,*] :count Gäste', $covers);
        $roomId = $data['limit_room_id'] ?? null;
        $room = $roomId === null ? null : $location->rooms()->whereKey((int) $roomId)->value('name');
        $where = $room === null ? __('im ganzen Betrieb') : __('im Raum „:room“', ['room' => $room]);
        $range = $this->range($data['limit_from'] ?? null, $data['limit_until'] ?? null);
        $verb = $first ? __('gelten') : __('es gelten');

        return $range === null
            ? __(':verb höchstens :guests gleichzeitig :where', ['verb' => $verb, 'guests' => $guests, 'where' => $where])
            : __(':verb :range höchstens :guests gleichzeitig :where', ['verb' => $verb, 'range' => $range, 'guests' => $guests, 'where' => $where]);
    }

    /** @param array<string, mixed> $data */
    private function lockPart(Location $location, array $data, bool $first): string
    {
        $items = [];
        $rooms = $this->ids($data['lock_rooms'] ?? null);
        if ($rooms !== []) {
            foreach ($location->rooms()->whereIn('id', $rooms)->orderBy('name')->orderBy('id')->pluck('name') as $name) {
                $items[] = $this->quoted((string) $name);
            }
        }
        $tables = $this->ids($data['lock_tables'] ?? null);
        if ($tables !== []) {
            foreach ($location->tables()->whereIn('id', $tables)->orderBy('name')->orderBy('id')->pluck('name') as $name) {
                $items[] = __('Tisch :table', ['table' => $name]);
            }
        }
        if ($items === []) {
            return '';
        }

        $list = $this->joinList($items);
        $one = count($items) === 1;
        $part = $first
            ? ($one ? __('ist :list gesperrt', ['list' => $list]) : __('sind :list gesperrt', ['list' => $list]))
            : ($one ? __(':list ist gesperrt', ['list' => $list]) : __(':list sind gesperrt', ['list' => $list]));
        $range = $this->range($data['lock_from'] ?? null, $data['lock_until'] ?? null);

        return $range === null ? $part : $part.' '.$range;
    }

    /** "von 18:00 bis 22:00", oder null ohne Uhrzeiten. */
    private function range(mixed $from, mixed $until): ?string
    {
        return is_string($from) && is_string($until) ? __('von :from bis :until', ['from' => $from, 'until' => $until]) : null;
    }

    /**
     * Aufzählung: "A", "A und B", "A, B und C".
     *
     * @param  list<string>  $items
     */
    private function joinList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' '.__('und').' '.$last;
    }

    private function quoted(string $name): string
    {
        return '„'.$name.'“';
    }

    private function date(string $day): string
    {
        return CarbonImmutable::parse($day)->format('d.m.Y');
    }
}

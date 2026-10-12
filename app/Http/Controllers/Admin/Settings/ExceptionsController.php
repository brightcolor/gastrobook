<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Services\AuditLogger;
use App\Services\ScheduleExceptions;
use App\Support\CapacityModes;
use App\Support\NameOrder;
use App\Support\ScheduleExceptionRules;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Einstellungen › Platz & Zeiten › Ausnahmen: die Liste mit dem Formular
 * (show) und die Speicherwege zum Anlegen, Ändern und Löschen samt Vorschau
 * „So wirkt es“ für den gewählten Standort. Die Seite und alle Wege
 * verlangen die Rechte für Sondertage und für Sperrzeiten, weil eine
 * Ausnahme beides schreiben kann.
 *
 * @phpstan-import-type Entry from ScheduleExceptions
 */
class ExceptionsController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly ScheduleExceptions $exceptions,
    ) {}

    /**
     * Die Liste der Ausnahmen (kommend nach Monat, vergangen getrennt) und das
     * Formular. Mit ?tisch={id} öffnet „Sperren…“ eines Tisches die Seite mit
     * der Adresse #ausnahme-neu: Die Seitenleiste erscheint mit dem Tisch
     * vorbelegt. Eine fremde oder unbekannte Nummer wird übergangen.
     */
    public function show(Request $request): View
    {
        $location = $this->location();
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404, __('Es ist kein Betrieb gewählt. Bitte neu anmelden und die Seite neu laden.'));
        $salon = $tenant->isSalon();
        $entries = $this->exceptions->entries($location);
        $rooms = $salon ? collect() : $this->rooms($location);
        // Räume und Tische wirken nur, wo Swayy Tische vergibt; sonst entfällt auch die Vorbelegung über ?tisch=.
        $assignsTables = CapacityModes::assignsTables($location);
        $presetTable = $assignsTables ? $this->presetTable($request, $this->offered($rooms, $this->blank(null))) : null;

        return view('admin.settings.exceptions', [
            'upcoming' => $entries['upcoming'],
            'past' => $entries['past'],
            'blank' => $this->blank($presetTable),
            'presetTable' => $presetTable,
            'assignsTables' => $assignsTables,
            'capacityMode' => CapacityModes::of($location),
            // Räume und Tische, die ein Formular anbietet: die aktiven und die, auf die seine Werte verweisen.
            'roomsFor' => fn (array $values): Collection => $this->offered($rooms, $values),
            'salon' => $salon,
            'limits' => (array) config('swayy.exceptions'),
            'previewDelay' => (int) config('swayy.ui.preview_delay_ms'),
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->location();
        $data = $this->checked($request, $location);

        $result = $this->exceptions->save($location, $data, null, $request->user()?->id);
        $this->audit->log('exception.created', null, null, $this->values($result['entry']));

        return $this->saved($request, $this->message($result['overlapping']), true);
    }

    public function update(Request $request, string $key): mixed
    {
        $location = $this->location();
        $before = $this->exceptions->find($location, $key) ?? $this->gone($request);
        $data = $this->checked($request, $location);

        try {
            $result = $this->exceptions->save($location, $data, $key, $request->user()?->id);
        } catch (NotFoundHttpException) {
            // Ein gleichzeitiges Löschen war schneller.
            $this->gone($request);
        }
        $this->audit->log('exception.updated', null, $this->values($before), $this->values($result['entry']));

        return $this->saved($request, $this->message($result['overlapping']), true);
    }

    public function destroy(Request $request, string $key): mixed
    {
        $location = $this->location();
        $before = $this->exceptions->find($location, $key) ?? $this->gone($request);

        if (! $this->exceptions->delete($location, $key)) {
            $this->gone($request);
        }
        $this->audit->log('exception.deleted', null, $this->values($before));

        return $this->saved($request, __('Ausnahme gelöscht.'), true);
    }

    /**
     * Der Satz „So wirkt es“ zu den Feldern des Formulars, auch ungespeichert.
     * Schreibt nichts. Beim Bearbeiten nennt das Feld "key" den Eintrag; seine
     * eigenen Zeilen zählen für die Zeiten des Tages nicht mit. Ein fremder
     * oder unbekannter Schlüssel ergibt 404.
     */
    public function preview(Request $request): mixed
    {
        $location = $this->location();
        $key = $this->previewKey($request, $location);
        $data = $this->checked($request, $location);

        $claimed = $this->exceptions->claimedDay($location, $data, $key);
        if ($claimed !== null) {
            throw ValidationException::withMessages(['from' => $claimed]);
        }

        return response()->json(['sentence' => $this->exceptions->describe($location, $data, $key)]);
    }

    /**
     * Alle Räume des Standorts mit allen Tischen (ohne gelöschte), in der
     * Reihenfolge von Räume & Tische. Welche davon ein Formular anbietet,
     * entscheidet offered().
     *
     * @return Collection<int, Room>
     */
    private function rooms(Location $location): Collection
    {
        $rooms = NameOrder::inOrder($location->rooms()->with('tables')->get());
        $rooms->each(fn (Room $room) => $room->setRelation('tables', NameOrder::inOrder($room->tables)));

        return $rooms;
    }

    /**
     * Die Räume und Tische, die ein Formular anbietet: die aktiven Tische
     * aktiver Räume und alles, worauf die Werte des Formulars verweisen (gesperrte
     * Räume und Tische, der Raum der Gästegrenze). Ein Verweis auf etwas
     * Inaktives bleibt so auswählbar; fehlte er im Formular, ginge er beim
     * Speichern verloren, und eine Grenze für einen Raum gälte danach für den
     * ganzen Betrieb. Jede Antwort trägt eigene Kopien der Räume.
     *
     * @param  Collection<int, Room>  $rooms  aus rooms()
     * @param  array<string, mixed>  $values  Felder des Formulars (blank() oder Eintrag['form'])
     * @return Collection<int, Room>
     */
    private function offered(Collection $rooms, array $values): Collection
    {
        $roomIds = array_map('intval', array_filter(
            [...(array) ($values['lock_rooms'] ?? []), $values['limit_room_id'] ?? null],
            fn ($id) => $id !== null,
        ));
        $tableIds = array_map('intval', (array) ($values['lock_tables'] ?? []));

        return $rooms
            ->map(function (Room $room) use ($roomIds, $tableIds): ?Room {
                $tables = $room->tables->filter(
                    fn (RestaurantTable $table) => ($room->is_active && $table->is_active) || in_array((int) $table->id, $tableIds, true),
                )->values();
                if (! $room->is_active && $tables->isEmpty() && ! in_array((int) $room->id, $roomIds, true)) {
                    return null;
                }
                $copy = clone $room;
                $copy->setRelation('tables', $tables);

                return $copy;
            })
            ->filter()
            ->values();
    }

    /**
     * Die Nummer des Tisches aus ?tisch={id}, wenn das Formular ihn anbietet
     * (ein aktiver Tisch dieses Standorts), sonst null.
     *
     * @param  Collection<int, Room>  $rooms  aus offered()
     */
    private function presetTable(Request $request, Collection $rooms): ?int
    {
        $id = filter_var($request->query('tisch'), FILTER_VALIDATE_INT);
        if ($id === false) {
            return null;
        }

        return $rooms->contains(fn (Room $room) => $room->tables->contains('id', $id)) ? $id : null;
    }

    /**
     * Die Felder des Formulars zum Anlegen. Ein vorbelegter Tisch wählt „Räume
     * oder Tische sperren“ mit diesem Tisch.
     *
     * @return array<string, mixed>
     */
    private function blank(?int $table): array
    {
        return [
            'from' => null, 'until' => null, 'label' => null,
            'effects' => $table === null ? [] : ['lock'], 'windows' => [],
            'limit_covers' => null, 'limit_room_id' => null, 'limit_from' => null, 'limit_until' => null,
            'lock_rooms' => [], 'lock_tables' => $table === null ? [] : [$table], 'lock_from' => null, 'lock_until' => null,
        ];
    }

    private function location(): Location
    {
        $location = $this->context->location();
        abort_if($location === null, 404, __('Es ist kein Standort gewählt. Bitte einen Standort wählen und die Seite neu laden.'));

        return $location;
    }

    /**
     * 404 mit einer Meldung, die Ursache und nächsten Schritt nennt. Die
     * Verwaltung ersetzt den Text eines abort(404) durch ihren allgemeinen
     * Text für fehlende Einträge (AdminJsonErrors); die JSON-Antwort mit der
     * eigenen Meldung kommt unverändert an.
     */
    private function gone(Request $request): never
    {
        $message = ScheduleExceptions::gone();
        if ($request->expectsJson()) {
            throw new HttpResponseException(response()->json(['message' => $message], 404));
        }

        abort(404, $message);
    }

    /**
     * Der Schlüssel des bearbeiteten Eintrags in der Vorschau: null ohne Angabe,
     * sonst ein Schlüssel dieses Standorts (404 für alles andere).
     */
    private function previewKey(Request $request, Location $location): ?string
    {
        $key = $request->input('key');
        if ($key === null) {
            return null;
        }
        if (! is_string($key) || preg_match('/^'.ScheduleExceptionRules::KEY_PATTERN.'$/', $key) !== 1 || $this->exceptions->find($location, $key) === null) {
            $this->gone($request);
        }

        return $key;
    }

    /**
     * Prüft die Felder der gewählten Wirkungen einzeln
     * (ScheduleExceptionRules::rules) und danach gegeneinander und gegen den
     * Standort (ScheduleExceptionRules::after). Die Felder nicht gewählter
     * Wirkungen bleiben außen vor: Das Formular sendet auch die Felder
     * ausgeblendeter Abschnitte.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(Request $request, Location $location): array
    {
        $salon = (bool) $this->context->tenant()?->isSalon();
        $data = validator(
            ScheduleExceptionRules::selected($request->all()),
            ScheduleExceptionRules::rules($salon),
            ScheduleExceptionRules::messages($salon),
        )->validate();

        $errors = ScheduleExceptionRules::after($location, $data, $salon, CarbonImmutable::now($location->timezone)->startOfDay());
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Meldung nach dem Speichern. Reservierungen im Zeitraum bleiben bestehen;
     * die Meldung sagt das und erinnert daran, die Gäste zu informieren.
     */
    private function message(int $overlapping): string
    {
        $message = __('Ausnahme gespeichert.');
        if ($overlapping < 1) {
            return $message;
        }

        $salon = (bool) $this->context->tenant()?->isSalon();
        $note = match (true) {
            $overlapping === 1 && $salon => __('In diesem Zeitraum liegt 1 Termin; er bleibt bestehen. Informiere die Kunden bei Bedarf.'),
            $overlapping === 1 => __('In diesem Zeitraum liegt 1 Reservierung; sie bleibt bestehen. Informiere die Gäste bei Bedarf.'),
            $salon => __('In diesem Zeitraum liegen :count Termine; sie bleiben bestehen. Informiere die Kunden bei Bedarf.', ['count' => $overlapping]),
            default => __('In diesem Zeitraum liegen :count Reservierungen; sie bleiben bestehen. Informiere die Gäste bei Bedarf.', ['count' => $overlapping]),
        };

        return $message.' '.$note;
    }

    /**
     * Die Werte eines Eintrags fürs Protokoll: Zeitraum, Bezeichnung und die
     * Wirkungen in Worten.
     *
     * @param  Entry  $entry
     * @return array<string, mixed>
     */
    private function values(array $entry): array
    {
        return [
            'from' => $entry['from'],
            'until' => $entry['until'],
            'label' => $entry['label'],
            'effects' => implode(', ', array_column($entry['effects'], 'text')),
        ];
    }
}

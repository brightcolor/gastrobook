<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Services\AuditLogger;
use App\Support\NameOrder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Mitarbeiter (Salon): wer Termine annimmt,
 * mit Leistungen, Arbeitszeiten und Abwesenheiten. Gilt für den gewählten
 * Standort. Der Einrichtungsassistent legt die erste Person über store() an
 * und liest die Kennung aus der Antwort.
 */
class StaffMemberController extends Controller
{
    use RespondsToSettingsForms;

    /** Wochentage in der Zählung der Tabelle (0 = Montag). */
    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        $members = StaffMember::where('location_id', $location->id)
            ->with(['services', 'workingHours', 'absences' => fn ($query) => $query->where('ends_at', '>=', now())->orderBy('starts_at')])
            ->get();

        $members = NameOrder::inOrder($members);

        return view('admin.staff.index', [
            'location' => $location,
            'members' => $members,
            'absenceNotes' => $this->absenceNotes($location, $members),
            'services' => NameOrder::inOrder(Service::where('location_id', $location->id)->where('is_active', true)->get()),
            'defaultColor' => config('swayy.ui.default_staff_color'),
            'limits' => (array) config('swayy.salon'),
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules($location->id), $this->messages());
        [$serviceIds, $problem] = $this->serviceSelection($location->id, $validated);
        if ($problem !== null) {
            return $this->refuse($request, ['service_ids' => $problem]);
        }

        $values = $this->values($request, $validated);
        $member = DB::transaction(function () use ($location, $values, $serviceIds): StaffMember {
            $member = StaffMember::create([
                'tenant_id' => $location->tenant_id,
                'location_id' => $location->id,
                ...$values,
            ]);
            $member->services()->sync($serviceIds);

            return $member;
        });

        $this->audit->log('staff_member.created', $member, null, $values + ['service_ids' => $serviceIds->all()]);

        $message = __('„:name“ angelegt.', ['name' => $member->name]);
        // id: Der Einrichtungsassistent merkt sich die angelegte Person.
        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'id' => $member->id]);
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules($member->location_id), $this->messages());
        [$serviceIds, $problem] = $this->serviceSelection($member->location_id, $validated);
        if ($problem !== null) {
            return $this->refuse($request, ['service_ids' => $problem]);
        }

        $values = $this->values($request, $validated);
        $old = $member->only(array_keys($values)) + ['service_ids' => $this->assignedServices($member)->all()];

        // Die Auswahl der Seite zeigt nur aktive Leistungen; was pausiert, bleibt der Person zugeordnet.
        $paused = $member->services()->where('services.is_active', false)->pluck('services.id');
        $assigned = $serviceIds->merge($paused)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        DB::transaction(function () use ($member, $values, $assigned): void {
            $member->update($values);
            $member->services()->sync($assigned);
        });

        $this->audit->log('staff_member.updated', $member, $old, $values + ['service_ids' => $assigned->all()]);

        return $this->saved($request, __('„:name“ gespeichert.', ['name' => $member->name]));
    }

    public function destroy(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        // Die Person bleibt beim Löschen als Eintrag erhalten (Soft Delete), und der Fremdschlüssel eines Termins hielte auch
        // auf die gelöschte Person. Darum sperrt die Anfrage zuerst die Zeile der Person (FOR UPDATE): Sie wartet auf
        // Buchungen, die die Person gerade verwenden (ReservationLifecycleService::create liest sie unter einer geteilten
        // Sperre), zählt danach die kommenden Termine und hält neue Buchungen an, bis das Löschen festgeschrieben ist. Eine
        // Buchung findet die Person danach als gelöscht. Ein zweiter Löschauftrag (Doppelklick, zweites Fenster) findet die
        // Zeile nicht mehr und schreibt nichts ins Protokoll.
        $upcoming = 0;
        $deleted = false;
        DB::transaction(function () use ($member, &$upcoming, &$deleted): void {
            $locked = StaffMember::whereKey($member->id)->where('location_id', $member->location_id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $upcoming = $this->upcomingAppointments($locked);
            if ($upcoming > 0) {
                return;
            }

            $locked->delete();
            $deleted = true;
        });

        // Der Protokolleintrag folgt nach dem Festschreiben: Die Sperre auf der Person hält nur so lange wie nötig.
        if ($deleted) {
            $this->audit->log('staff_member.deleted', $member, ['name' => $member->name]);
        }

        if ($upcoming > 0) {
            return $this->failed($request, 'member', trans_choice(
                '„:name“ hat noch einen kommenden oder laufenden Termin. Bitte den Termin zuerst einer anderen Person zuweisen, stornieren oder bis nach dem Termin warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Buchbar“.|„:name“ hat noch :count kommende oder laufende Termine. Bitte die Termine zuerst anderen Personen zuweisen, stornieren oder bis nach den Terminen warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Buchbar“.',
                $upcoming,
                ['name' => $member->name, 'count' => $upcoming],
            ));
        }

        return $this->saved($request, __('„:name“ gelöscht.', ['name' => $member->name]));
    }

    /**
     * Wochenzeiten einer Person ersetzen. Ohne Eintrag gelten die
     * Öffnungszeiten des Standorts. Die Seite behält die Schlüssel neuer Zeilen
     * (n1, n2 …) auch nach dem Speichern und sendet sie beim nächsten Mal
     * erneut; darum ersetzt jede Anfrage die ganze Liste.
     */
    public function updateWorkingHours(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->hourRules(), $this->hourMessages());
        /** @var Collection<array-key, array{weekday: int|string, starts_at: string, ends_at: string}> $hours */
        $hours = collect($validated['hours'] ?? []);
        $problems = $this->hourProblems($hours);
        if ($problems !== []) {
            return $this->refuse($request, $problems);
        }

        $rows = $hours
            ->map(fn (array $hour): array => ['weekday' => (int) $hour['weekday'], 'starts_at' => $hour['starts_at'], 'ends_at' => $hour['ends_at']])
            ->sortBy([['weekday', 'asc'], ['starts_at', 'asc'], ['ends_at', 'asc']])
            ->values();

        // Die Zeile der Person ist gesperrt: Zwei gleichzeitige Speicherungen ersetzen die Liste nacheinander, und die
        // Zeitfenster der einen vermischen sich nicht mit denen der anderen.
        $old = collect();
        DB::transaction(function () use ($member, $rows, &$old): void {
            $locked = StaffMember::whereKey($member->id)->where('location_id', $member->location_id)->lockForUpdate()->first();
            abort_if($locked === null, 404);

            $old = $locked->workingHours()->get();
            $locked->workingHours()->delete();
            foreach ($rows as $row) {
                StaffWorkingHour::create(['tenant_id' => $locked->tenant_id, 'staff_member_id' => $locked->id, ...$row]);
            }
        });

        $this->audit->log('staff_member.working_hours_updated', $member, ['hours' => $this->hourLabels($old)], ['hours' => $this->hourLabels($rows)]);

        return $this->saved($request, __('Arbeitszeiten von „:name“ gespeichert.', ['name' => $member->name]));
    }

    public function storeAbsence(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->absenceRules(), $this->absenceMessages());
        $timezone = (string) $this->context->location()?->timezone;

        $problems = $this->absenceWindowProblems($validated, $timezone);
        if ($problems !== []) {
            return $this->refuse($request, $problems);
        }

        // Ohne Uhrzeit ganze Tage (00:00 bis Tagesende) in der Zeitzone des Standorts, gespeichert in UTC.
        $startsLocal = CarbonImmutable::parse($validated['starts_on'].' '.($validated['starts_time'] ?? '00:00'), $timezone);
        $endsLocal = isset($validated['ends_time'])
            ? CarbonImmutable::parse($validated['ends_on'].' '.$validated['ends_time'], $timezone)
            : CarbonImmutable::parse($validated['ends_on'], $timezone)->endOfDay();

        if ($endsLocal->lte($startsLocal)) {
            return $this->refuse($request, ['ends_time' => __('Das Ende der Abwesenheit liegt vor dem Beginn. Bitte Datum oder Uhrzeit des Endes später wählen.')]);
        }

        // Eintragen und Zählen laufen unter der Sperre der Person (FOR UPDATE), wie das Löschen: Eine Buchung, die die Person
        // gerade liest (FOR SHARE), steht danach in der Zählung, und eine Buchung danach findet die Abwesenheit.
        $booked = 0;
        $absence = DB::transaction(function () use ($member, $startsLocal, $endsLocal, $validated, &$booked): StaffAbsence {
            $locked = StaffMember::whereKey($member->id)->where('location_id', $member->location_id)->lockForUpdate()->first();
            abort_if($locked === null, 404);

            $absence = StaffAbsence::create([
                'tenant_id' => $locked->tenant_id,
                'staff_member_id' => $locked->id,
                'starts_at' => $startsLocal->utc(),
                'ends_at' => $endsLocal->utc(),
                'reason' => $validated['reason'] ?? null,
            ]);
            $booked = (int) $this->activeAppointments($locked)
                ->where('start_at', '<', $endsLocal->utc())
                ->where('end_at', '>', $startsLocal->utc())
                ->count();

            return $absence;
        });

        // Das Protokoll hält die Zeiten so fest, wie der Betrieb sie eingetragen hat (Ortszeit).
        $this->audit->log('staff_member.absence_created', $absence, null, [
            'starts_at' => $startsLocal->format('Y-m-d H:i'),
            'ends_at' => $endsLocal->format('Y-m-d H:i'),
            'reason' => $absence->reason,
        ]);

        $message = __('Abwesenheit für „:name“ eingetragen.', ['name' => $member->name]);

        // Die Abwesenheit sperrt neue Buchungen; vorhandene Termine in dieser Zeit bleiben bestehen und brauchen eine andere Person.
        if ($booked > 0) {
            $message .= ' '.trans_choice(
                'In dieser Zeit ist noch :count Termin gebucht. Bitte weise ihn einer anderen Person zu oder sage ihn ab.|In dieser Zeit sind noch :count Termine gebucht. Bitte weise sie anderen Personen zu oder sage sie ab.',
                $booked,
            );
        }

        return $this->saved($request, $message);
    }

    public function deleteAbsence(Request $request, StaffAbsence $absence): mixed
    {
        // Eine Seite, die vor dem Löschen der Person geöffnet wurde, kennt die Abwesenheit noch; die Person ist dann nicht mehr da.
        $member = $absence->staffMember;
        abort_if($member === null || $member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $timezone = (string) $this->context->location()?->timezone;
        $old = [
            'starts_at' => $absence->starts_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'ends_at' => $absence->ends_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'reason' => $absence->reason,
        ];

        // Ein zweiter Löschauftrag findet die Zeile nicht mehr und schreibt nichts ins Protokoll.
        if (StaffAbsence::whereKey($absence->getKey())->delete() > 0) {
            $this->audit->log('staff_member.absence_deleted', $absence, $old);
        }

        return $this->saved($request, __('Abwesenheit gelöscht.'));
    }

    private function ensureSalon(): void
    {
        abort_unless($this->context->tenant()?->isSalon(), 403, __('Mitarbeiter mit Arbeitszeiten gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.'));
    }

    /**
     * Ablehnung nach der Prüfung der Felder. Ohne Skript bleibt die Eingabe auf der Seite stehen.
     *
     * @param  array<string, string>  $errors  Feld => Meldung
     */
    private function refuse(Request $request, array $errors): mixed
    {
        if (! $request->wantsJson()) {
            $request->flash();
        }

        return $this->rejected($request, $errors);
    }

    /**
     * Kommende und laufende Termine eines Standorts (ihr Ende ist noch nicht erreicht, aktive Status). Der Löschschutz, die
     * Antwort auf eine neue Abwesenheit und die Liste der Abwesenheiten zählen mit derselben Grundlage.
     *
     * @return Builder<Reservation>
     */
    private function activeAppointmentsAt(int $locationId): Builder
    {
        return Reservation::where('location_id', $locationId)
            ->where('end_at', '>=', now())
            ->whereIn('status', ReservationStatus::activeStatuses());
    }

    /**
     * Die kommenden und laufenden Termine der Person.
     *
     * @return Builder<Reservation>
     */
    private function activeAppointments(StaffMember $member): Builder
    {
        return $this->activeAppointmentsAt($member->location_id)->where('staff_member_id', $member->id);
    }

    /**
     * Zu jeder kommenden Abwesenheit der gelisteten Personen die Zahl der aktiven Termine, die in ihre Zeit fallen, und der erste
     * betroffene Tag (Ortszeit). Eine Abfrage für die ganze Seite; zugeordnet wird in PHP.
     *
     * @param  Collection<int, StaffMember>  $members  mit geladenen Abwesenheiten
     * @return array<int, array{count: int, date: string}> Kennung der Abwesenheit => Hinweis
     */
    private function absenceNotes(Location $location, Collection $members): array
    {
        $absences = $members->flatMap(fn (StaffMember $member) => $member->absences);
        if ($absences->isEmpty()) {
            return [];
        }

        $booked = $this->activeAppointmentsAt($location->id)
            ->whereIn('staff_member_id', $members->pluck('id')->all())
            ->where('start_at', '<', $absences->max('ends_at'))
            ->where('end_at', '>', $absences->min('starts_at'))
            ->get(['id', 'staff_member_id', 'start_at', 'end_at'])
            ->groupBy('staff_member_id');

        $notes = [];
        foreach ($members as $member) {
            foreach ($member->absences as $absence) {
                $hits = $booked->get($member->id, new Collection)
                    ->filter(fn (Reservation $reservation) => $reservation->start_at->lt($absence->ends_at) && $reservation->end_at->gt($absence->starts_at));
                if ($hits->isEmpty()) {
                    continue;
                }

                // Beginnt der Termin vor der Abwesenheit, ist der Beginn der Abwesenheit der erste betroffene Zeitpunkt.
                $first = $hits->map(fn (Reservation $reservation) => $reservation->start_at->max($absence->starts_at))->min();
                $notes[$absence->id] = ['count' => $hits->count(), 'date' => $first->copy()->setTimezone($location->timezone)->toDateString()];
            }
        }

        return $notes;
    }

    private function upcomingAppointments(StaffMember $member): int
    {
        return (int) $this->activeAppointments($member)->count();
    }

    /**
     * Die Grenzen kommen aus config/swayy.php (salon.*); alle liegen innerhalb der Spalten (name varchar(255), bio text,
     * reason varchar(120), color varchar(9)). Die Auswahl der Leistungen hat höchstens so viele Einträge, wie der Standort
     * Leistungen hat; das hält die Abfrage klein.
     *
     * @return array<string, list<string>>
     */
    private function rules(int $locationId): array
    {
        $limits = (array) config('swayy.salon');

        return [
            'name' => ['required', 'string', 'max:'.(int) $limits['staff_name_max']],
            'bio' => ['nullable', 'string', 'max:'.(int) $limits['staff_bio_max']],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            // Alle Aufrufer senden den Schalter mit: die Seite (verstecktes 0 vor dem Kästchen) und der Einrichtungsassistent.
            // Fehlt er, wird die Person nicht still auf „buchbar“ gesetzt.
            'is_active' => ['required', 'boolean'],
            'service_ids' => ['nullable', 'array', 'max:'.Service::where('location_id', $locationId)->count()],
        ];
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Ursache und den nächsten Schritt.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        $color = __('Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.');
        $switch = __('Bitte „Buchbar“ ein- oder ausschalten.');
        $name = __('Bitte einen Namen für die Person eintragen.');
        $bio = __('Bitte die Kurzvorstellung als Text eintragen.');

        return [
            'name.required' => $name,
            'name.string' => $name,
            'name.max' => __('Der Name darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
            'bio.string' => $bio,
            'bio.max' => __('Die Kurzvorstellung darf höchstens :max Zeichen lang sein. Bitte kürze sie.'),
            'color.string' => $color,
            'color.regex' => $color,
            'is_active.required' => $switch,
            'is_active.boolean' => $switch,
            'service_ids.array' => self::invalidServices(),
            // Mehr Einträge, als der Standort Leistungen hat: Mindestens einer gehört nicht dazu.
            'service_ids.max' => self::unknownServices(),
        ];
    }

    private static function invalidServices(): string
    {
        return __('Die Auswahl der Leistungen ist ungültig. Bitte die Seite neu laden und die Leistungen neu wählen.');
    }

    private static function unknownServices(): string
    {
        return __('Mindestens eine gewählte Leistung gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Leistungen neu wählen.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(Request $request, array $validated): array
    {
        return [
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Die gewählten Leistungen und der Grund, warum sie nicht passen, oder null. Eine Leistung, die nicht zu diesem Standort
     * gehört oder entfernt wurde, lehnt die Eingabe ab; sonst bekäme die Person andere Leistungen, als die Seite zeigt.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Collection<int, int>, 1: ?string}
     */
    private function serviceSelection(int $locationId, array $validated): array
    {
        $requested = collect($validated['service_ids'] ?? [])->values();
        if ($requested->contains(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false)) {
            return [collect(), self::invalidServices()];
        }

        $ids = $requested->map(fn ($id) => (int) $id);
        if ($ids->unique()->count() < $ids->count()) {
            return [collect(), self::invalidServices()];
        }

        $known = Service::where('location_id', $locationId)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        if ($known->count() < $ids->count()) {
            return [collect(), self::unknownServices()];
        }

        return [$known->sort()->values(), null];
    }

    /**
     * @return Collection<int, int>
     */
    private function assignedServices(StaffMember $member): Collection
    {
        return $member->services()->pluck('services.id')->map(fn ($id) => (int) $id)->sort()->values();
    }

    /**
     * Die Zeitfenster einer Anfrage: höchstens so viele, wie die Einstellung erlaubt (staff_hours_max). Die Schlüssel der
     * Zeilen sind Zahlen oder n1, n2 … neuer Zeilen; Fehler tragen den Schlüssel im Namen (hours.n1.ends_at).
     *
     * @return array<string, list<string>>
     */
    private function hourRules(): array
    {
        return [
            'hours' => ['nullable', 'array', 'max:'.(int) config('swayy.salon.staff_hours_max')],
            'hours.*' => ['array'],
            'hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.starts_at' => ['required', 'date_format:H:i'],
            'hours.*.ends_at' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function hourMessages(): array
    {
        $invalid = __('Die Arbeitszeiten sind ungültig. Bitte die Seite neu laden und die Zeitfenster neu eintragen.');
        $weekday = __('Bitte bei jedem Zeitfenster einen Wochentag wählen.');

        return [
            'hours.array' => $invalid,
            'hours.*.array' => $invalid,
            'hours.max' => __('Eine Person kann höchstens :max Zeitfenster je Woche haben. Bitte Zeitfenster zusammenfassen oder entfernen.'),
            'hours.*.weekday.required' => $weekday,
            'hours.*.weekday.integer' => $weekday,
            'hours.*.weekday.min' => $weekday,
            'hours.*.weekday.max' => $weekday,
            'hours.*.starts_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.'),
            'hours.*.starts_at.date_format' => __('Bitte den Beginn als Uhrzeit eintragen, etwa 09:00.'),
            'hours.*.ends_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es endet.'),
            'hours.*.ends_at.date_format' => __('Bitte das Ende als Uhrzeit eintragen, etwa 18:00.'),
        ];
    }

    /**
     * Fehler an den Zeilen: Ende nicht nach dem Beginn (am Feld „Bis“) und Überschneidungen am selben Wochentag (am Feld
     * „Von“ der später beginnenden Zeile). Aneinander anschließende Zeitfenster überschneiden sich nicht.
     *
     * @param  Collection<array-key, array{weekday: int|string, starts_at: string, ends_at: string}>  $hours
     * @return array<string, string> Feld => Meldung
     */
    private function hourProblems(Collection $hours): array
    {
        $problems = [];
        $byDay = [];
        foreach ($hours as $key => $hour) {
            if ($hour['ends_at'] < $hour['starts_at']) {
                $problems["hours.{$key}.ends_at"] = __('Das Zeitfenster endet vor seinem Beginn. Bitte eine spätere Endzeit eintragen.');
            } elseif ($hour['ends_at'] === $hour['starts_at']) {
                $problems["hours.{$key}.ends_at"] = __('Das Zeitfenster beginnt und endet zur selben Uhrzeit. Bitte eine spätere Endzeit eintragen.');
            } else {
                $byDay[(int) $hour['weekday']][] = ['key' => $key, 'start' => $hour['starts_at'], 'end' => $hour['ends_at']];
            }
        }

        foreach ($byDay as $day => $windows) {
            usort($windows, fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
            // Verglichen wird mit dem Zeitfenster, das bisher am längsten läuft.
            $reach = null;
            foreach ($windows as $window) {
                if ($reach !== null && $window['start'] < $reach['end']) {
                    $problems["hours.{$window['key']}.starts_at"] = __('Dieses Zeitfenster überschneidet sich mit dem Zeitfenster am :day von :from bis :to Uhr. Bitte Beginn oder Ende so ändern, dass sich beide nicht überschneiden.', [
                        'day' => self::WEEKDAYS[$day], 'from' => $reach['start'], 'to' => $reach['end'],
                    ]);
                }
                if ($reach === null || $window['end'] > $reach['end']) {
                    $reach = $window;
                }
            }
        }

        return $problems;
    }

    /**
     * Zeitfenster als Text für das Protokoll („Montag 09:00–12:00“), nach Wochentag und Beginn.
     *
     * @param  Collection<array-key, StaffWorkingHour|array{weekday: int, starts_at: string, ends_at: string}>  $hours
     * @return list<string>
     */
    private function hourLabels(Collection $hours): array
    {
        return $hours
            ->map(fn ($hour): array => [
                'weekday' => (int) (is_array($hour) ? $hour['weekday'] : $hour->weekday),
                'starts_at' => substr((string) (is_array($hour) ? $hour['starts_at'] : $hour->starts_at), 0, 5),
                'ends_at' => substr((string) (is_array($hour) ? $hour['ends_at'] : $hour->ends_at), 0, 5),
            ])
            ->sortBy([['weekday', 'asc'], ['starts_at', 'asc'], ['ends_at', 'asc']])
            ->map(fn (array $hour): string => self::WEEKDAYS[$hour['weekday']].' '.$hour['starts_at'].'–'.$hour['ends_at'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<string>>
     */
    private function absenceRules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'starts_time' => ['nullable', 'date_format:H:i'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'ends_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:'.(int) config('swayy.salon.absence_reason_max')],
        ];
    }

    /**
     * Beginn und Ende liegen höchstens absence_window_years Jahre vor oder nach heute (Tag am Standort). Ein Tippfehler im Jahr
     * (0026) fällt so auf, und jedes Datum liegt im Bereich der Zeitstempel-Spalten, auch nach der Umrechnung in UTC.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string> Feld => Meldung
     */
    private function absenceWindowProblems(array $validated, string $timezone): array
    {
        $years = (int) config('swayy.salon.absence_window_years');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $today->subYears($years);
        $until = $today->addYears($years);

        $problems = [];
        foreach (['starts_on', 'ends_on'] as $field) {
            $day = CarbonImmutable::parse($validated[$field], $timezone);
            if ($day->lt($from) || $day->gt($until)) {
                $problems[$field] = __('Das Datum liegt mehr als :years Jahre von heute entfernt. Bitte das Jahr prüfen und ein Datum zwischen :from und :until wählen.', [
                    'years' => $years, 'from' => $from->format('d.m.Y'), 'until' => $until->format('d.m.Y'),
                ]);
            }
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    private function absenceMessages(): array
    {
        $time = __('Bitte die Uhrzeit als Stunden und Minuten eintragen, etwa 09:00.');

        return [
            'starts_on.required' => __('Bitte das Datum eintragen, an dem die Abwesenheit beginnt.'),
            'starts_on.date_format' => __('Bitte das Datum des Beginns über die Datumswahl eintragen.'),
            'ends_on.required' => __('Bitte das Datum eintragen, an dem die Abwesenheit endet.'),
            'ends_on.date_format' => __('Bitte das Datum des Endes über die Datumswahl eintragen.'),
            'ends_on.after_or_equal' => __('Das Ende der Abwesenheit liegt vor ihrem Beginn. Bitte ein Enddatum am oder nach dem Beginn wählen.'),
            'starts_time.date_format' => $time,
            'ends_time.date_format' => $time,
            'reason.string' => __('Bitte den Grund als Text eintragen.'),
            'reason.max' => __('Der Grund darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
        ];
    }
}

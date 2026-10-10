<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Leistungen (Salon): was Gäste buchen,
 * mit Dauer, Preis und den Personen, die es anbieten. Gilt für den gewählten
 * Standort. Der Einrichtungsassistent legt die erste Leistung über store() an
 * und sendet den Preis in Cent (price_minor); die Seite sendet Euro (price).
 */
class ServiceController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        // Die Liste nennt, wer die Leistung anbietet; Gäste buchen nur aktive Personen.
        $services = Service::where('location_id', $location->id)
            ->with(['staff' => fn ($query) => $query->where('staff_members.is_active', true)])
            ->get();
        $staff = StaffMember::where('location_id', $location->id)->where('is_active', true)->get();

        return view('admin.services.index', [
            'location' => $location,
            'services' => $this->inOrder($services),
            'staff' => $this->inOrder($staff),
            'limits' => (array) config('swayy.salon'),
            'defaultColor' => config('swayy.ui.default_label_color'),
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules($location->id, creating: true), $this->messages());
        [$staffIds, $problem] = $this->staffSelection($location->id, $validated);
        if ($problem !== null) {
            return $this->failed($request, 'staff_ids', $problem);
        }

        $values = $this->values($request, $validated);
        $service = DB::transaction(function () use ($location, $values, $staffIds): Service {
            $service = Service::create([
                'tenant_id' => $location->tenant_id,
                'location_id' => $location->id,
                ...$values,
            ]);
            $service->staff()->sync($staffIds);

            return $service;
        });

        $this->audit->log('service.created', $service, null, $values + ['staff_ids' => $staffIds->all()]);

        return $this->saved($request, __('Leistung „:name“ angelegt.', ['name' => $service->name]));
    }

    public function update(Request $request, Service $service): mixed
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules($service->location_id, creating: false), $this->messages());
        [$staffIds, $problem] = $this->staffSelection($service->location_id, $validated);
        if ($problem !== null) {
            return $this->failed($request, 'staff_ids', $problem);
        }

        $values = $this->values($request, $validated);
        $old = $service->only(array_keys($values)) + ['staff_ids' => $this->assignedStaff($service)->all()];

        // Die Auswahl der Seite zeigt nur aktive Personen; wer pausiert, bleibt der Leistung zugeordnet.
        $paused = $service->staff()->where('staff_members.is_active', false)->pluck('staff_members.id');
        $assigned = $staffIds->merge($paused)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        DB::transaction(function () use ($service, $values, $assigned): void {
            $service->update($values);
            $service->staff()->sync($assigned);
        });

        $this->audit->log('service.updated', $service, $old, $values + ['staff_ids' => $assigned->all()]);

        return $this->saved($request, __('Leistung „:name“ gespeichert.', ['name' => $service->name]));
    }

    public function destroy(Request $request, Service $service): mixed
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        // Die Leistung bleibt beim Löschen als Eintrag erhalten (Soft Delete), und der Fremdschlüssel eines Termins hielte
        // auch auf die gelöschte Leistung. Darum sperrt die Anfrage zuerst die Zeile der Leistung (FOR UPDATE): Sie wartet auf
        // Buchungen, die die Leistung gerade verwenden (deren Fremdschlüssel hält die Zeile), zählt danach die kommenden Termine
        // und hält neue Buchungen an, bis das Löschen festgeschrieben ist. Eine Buchung liest die Leistung unter einer geteilten
        // Sperre (ReservationLifecycleService) und findet sie danach als gelöscht. Ein zweiter Löschauftrag (Doppelklick,
        // zweites Fenster) findet die Zeile nicht mehr und schreibt nichts ins Protokoll.
        $upcoming = 0;
        $deleted = false;
        DB::transaction(function () use ($service, &$upcoming, &$deleted): void {
            $locked = Service::whereKey($service->id)->where('location_id', $service->location_id)->lockForUpdate()->first();
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

        // Der Protokolleintrag folgt nach dem Festschreiben: Sein Fremdschlüssel sperrt Zeilen anderer Tabellen,
        // und die Sperre auf der Leistung hält nur so lange wie nötig.
        if ($deleted) {
            $this->audit->log('service.deleted', $service, ['name' => $service->name]);
        }

        if ($upcoming > 0) {
            return $this->failed($request, 'service', trans_choice(
                '„:name“ ist noch für einen kommenden oder laufenden Termin gebucht. Bitte den Termin zuerst stornieren oder bis nach dem Termin warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Online buchbar“.|„:name“ ist noch für :count kommende oder laufende Termine gebucht. Bitte die Termine zuerst stornieren oder bis nach den Terminen warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Online buchbar“.',
                $upcoming,
                ['name' => $service->name, 'count' => $upcoming],
            ));
        }

        return $this->saved($request, __('Leistung „:name“ gelöscht.', ['name' => $service->name]));
    }

    private function ensureSalon(): void
    {
        abort_unless($this->context->tenant()?->isSalon(), 403, __('Leistungen gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.'));
    }

    /**
     * Kommende und laufende Termine (ihr Ende ist noch nicht erreicht), die die Leistung verwenden: als erste Leistung des
     * Termins oder in der Zuordnung mehrerer Leistungen. Ein Termin zählt einmal.
     */
    private function upcomingAppointments(Service $service): int
    {
        return (int) Reservation::where('location_id', $service->location_id)
            ->where('end_at', '>=', now())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where(fn ($query) => $query->where('service_id', $service->id)
                ->orWhereHas('services', fn ($services) => $services->where('services.id', $service->id)))
            ->count();
    }

    /**
     * Preis in Euro (Seite) oder in Cent (Einrichtungsassistent). Die Euro-Schreibweise ist eine Zahl mit Punkt und
     * höchstens zwei Nachkommastellen; values() rechnet sie mit Ziffern in Cent um.
     * Die Grenzen kommen aus config/swayy.php (salon.*); alle liegen innerhalb der Spalten (name varchar(255), duration_minutes
     * smallint, price_minor integer, color varchar(9)). Die Auswahl der Personen hat höchstens so viele Einträge, wie der
     * Standort Personen hat; das hält die Abfrage klein.
     *
     * @return array<string, list<string>>
     */
    private function rules(int $locationId, bool $creating): array
    {
        $limits = (array) config('swayy.salon');

        return [
            'name' => ['required', 'string', 'max:'.(int) $limits['service_name_max']],
            'description' => ['nullable', 'string', 'max:'.(int) $limits['service_description_max']],
            'duration_minutes' => ['required', 'integer', 'min:'.(int) $limits['service_duration_min'], 'max:'.(int) $limits['service_duration_max']],
            'price' => ['bail', 'nullable', 'required_without:price_minor', 'regex:/^\d+(\.\d{1,2})?$/D', 'numeric', 'max:'.(int) $limits['service_price_max']],
            'price_minor' => ['nullable', 'integer', 'min:0', 'max:'.((int) $limits['service_price_max'] * 100)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            // Der Einrichtungsassistent teilt sich die Route zum Anlegen und sendet den Schalter nur manchmal mit
            // (fehlt er, gilt die Leistung als online buchbar). Beim Bearbeiten sendet die Seite ihn immer; fehlt er dort, ist das ein Fehler.
            'is_active' => $creating ? ['nullable', 'boolean'] : ['required', 'boolean'],
            'staff_ids' => ['nullable', 'array', 'max:'.StaffMember::where('location_id', $locationId)->count()],
        ];
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Ursache und den nächsten Schritt.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        $limits = (array) config('swayy.salon');
        $name = __('Bitte einen Namen für die Leistung eintragen.');
        $description = __('Bitte die Beschreibung als Text eintragen.');
        $duration = __('Bitte die Dauer in Minuten als ganze Zahl eintragen, etwa 45.');
        $format = __('Bitte den Preis in Euro als Zahl mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 49.90. Mit 0 steht auf der Buchungsseite „auf Anfrage“.');
        $cents = __('Bitte den Preis in Cent als ganze Zahl eintragen, etwa 4990 für 49,90 €.');
        $color = __('Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.');
        $switch = __('Bitte „Online buchbar“ ein- oder ausschalten.');
        $priceMax = __('Der Preis darf höchstens :amount € betragen. Bitte einen kleineren Betrag eintragen.', [
            'amount' => number_format((int) $limits['service_price_max'], 0, ',', '.'),
        ]);

        return [
            'name.required' => $name,
            'name.string' => $name,
            'name.max' => __('Der Name darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
            'description.string' => $description,
            'description.max' => __('Die Beschreibung darf höchstens :max Zeichen lang sein. Bitte kürze sie.'),
            'duration_minutes.required' => $duration,
            'duration_minutes.integer' => $duration,
            'duration_minutes.min' => __('Die Dauer muss mindestens :min Minuten betragen. Bitte eine größere Zahl eintragen.'),
            'duration_minutes.max' => __('Die Dauer darf höchstens :max Minuten betragen. Bitte eine kleinere Zahl eintragen.'),
            'price.required_without' => __('Bitte den Preis in Euro eintragen, etwa 35 oder 49.90. Mit 0 steht auf der Buchungsseite „auf Anfrage“.'),
            'price.regex' => $format,
            'price.numeric' => $format,
            'price.max' => $priceMax,
            'price_minor.integer' => $cents,
            'price_minor.min' => __('Der Preis muss mindestens 0 betragen. Bitte 0 oder mehr eintragen.'),
            'price_minor.max' => $priceMax,
            'color.string' => $color,
            'color.regex' => $color,
            'is_active.required' => $switch,
            'is_active.boolean' => $switch,
            'staff_ids.array' => self::invalidStaff(),
            // Mehr Einträge, als der Standort Personen hat: Mindestens einer gehört nicht dazu.
            'staff_ids.max' => self::unknownStaff(),
        ];
    }

    private static function invalidStaff(): string
    {
        return __('Die Auswahl der Personen ist ungültig. Bitte die Seite neu laden und die Personen neu wählen.');
    }

    private static function unknownStaff(): string
    {
        return __('Mindestens eine gewählte Person gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Personen neu wählen.');
    }

    /**
     * Werte, wie sie in der Spalte stehen. Euro werden mit Ziffern in Cent umgerechnet: „49.9“ ergibt 4990, „1.15“ ergibt 115
     * (als Fließkomma ergäbe 1.15 × 100 den Wert 114.99999999999999).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(Request $request, array $validated): array
    {
        $priceMinor = isset($validated['price'])
            ? $this->euroToCents((string) $validated['price'])
            : (int) ($validated['price_minor'] ?? 0);

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'price_minor' => $priceMinor,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * Euro-Betrag in Ziffern („12“, „12.5“, „12.50“) in Cent.
     */
    private function euroToCents(string $euros): int
    {
        [$whole, $fraction] = array_pad(explode('.', $euros, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Die gewählten Personen und der Grund, warum sie nicht passen, oder null. Eine Person, die nicht zu diesem Standort
     * gehört oder entfernt wurde, lehnt die Eingabe ab; sonst bekäme die Leistung andere Personen, als die Seite zeigt.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Collection<int, int>, 1: ?string}
     */
    private function staffSelection(int $locationId, array $validated): array
    {
        $requested = collect($validated['staff_ids'] ?? [])->values();
        if ($requested->contains(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) === false)) {
            return [collect(), self::invalidStaff()];
        }

        $ids = $requested->map(fn ($id) => (int) $id);
        if ($ids->unique()->count() < $ids->count()) {
            return [collect(), self::invalidStaff()];
        }

        $known = StaffMember::where('location_id', $locationId)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        if ($known->count() < $ids->count()) {
            return [collect(), self::unknownStaff()];
        }

        return [$known->sort()->values(), null];
    }

    /**
     * @return Collection<int, int>
     */
    private function assignedStaff(Service $service): Collection
    {
        return $service->staff()->pluck('staff_members.id')->map(fn ($id) => (int) $id)->sort()->values();
    }

    /**
     * Nach Reihenfolge und Name sortiert, „Pflege 2“ vor „Pflege 10“. In PHP sortiert, damit die Reihenfolge unter
     * SQLite und PostgreSQL gleich ausfällt.
     *
     * @template TKey of array-key
     * @template TModel of Service|StaffMember
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
}

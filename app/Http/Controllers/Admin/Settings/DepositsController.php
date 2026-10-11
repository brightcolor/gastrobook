<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\DepositRule;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Support\MoneyInput;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Einstellungen › Zahlungen › Anzahlungen: Regeln, wann eine Online-Buchung
 * vorab bezahlt wird, und die Erstattung der Anzahlung bei Storno. Gilt für
 * den gewählten Standort. Die Regeln speichern je Eintrag über die
 * Seitenleiste (admin.settings.deposit-rules.*, auch von der alten
 * Sammelseite genutzt), die Erstattung über ihre eigene Karte.
 */
class DepositsController extends Controller
{
    use RespondsToSettingsForms;

    /** Wochentage in der Zählung der Regeln: 0 ist Montag (PaymentRequirementService). */
    public const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    /** Ausgeschriebene Namen derselben Tage, für Screenreader. */
    public const WEEKDAY_NAMES = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Prüfregeln der Erstattung. Die Buchungsregeln der alten Sammelseite
     * prüfen dieselben Felder.
     *
     * @return array<string, list<string>>
     */
    public static function refundRules(): array
    {
        return [
            'refund_mode' => ['bail', 'required', 'in:off,manual,auto'],
            'refund_percent' => ['bail', 'required', 'integer', 'min:0', 'max:100'],
            'refund_processing' => ['bail', 'required', 'in:immediate,scheduled'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function refundMessages(): array
    {
        $mode = __('Bitte „Aus“, „Von Hand freigeben“ oder „Automatisch“ wählen.');
        $percent = __('Bitte einen Prozentwert von 0 bis 100 eintragen.');
        $processing = __('Bitte „Sofort“ oder „Gesammelt nach Zeitplan“ wählen.');

        return [
            'refund_mode.required' => $mode,
            'refund_mode.in' => $mode,
            'refund_percent.required' => $percent,
            'refund_percent.integer' => $percent,
            'refund_percent.min' => $percent,
            'refund_percent.max' => $percent,
            'refund_processing.required' => $processing,
            'refund_processing.in' => $processing,
        ];
    }

    public function show(Request $request): View
    {
        $location = $this->context->location();
        $tenant = $this->context->tenant();
        abort_if($location === null || $tenant === null, 404);
        $salon = $tenant->isSalon();

        $rules = DepositRule::where('location_id', $location->id)->with('service')->orderBy('name')->get();
        $allRooms = Room::where('location_id', $location->id)->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.settings.deposits', [
            'rules' => $rules,
            'summaries' => $rules->mapWithKeys(fn (DepositRule $rule) => [$rule->id => $this->summary($rule, $salon, $allRooms->pluck('name', 'id'))]),
            // Räume gibt es bei Restaurants. Ein inaktiver Raum, an dem eine Regel hängt, bleibt wählbar: Fehlt er in der
            // Auswahl, stünde das Feld beim Bearbeiten auf "Alle Räume", und die Bindung wäre beim nächsten Speichern weg.
            'rooms' => $salon ? collect() : $allRooms->filter(fn (Room $room) => $room->is_active || $rules->contains('room_id', $room->id))->values(),
            // Aktive Leistungen plus die, an denen eine Regel hängt (aus demselben Grund wie bei den Räumen).
            'salonServices' => $salon
                ? Service::where('location_id', $location->id)
                    ->where(fn ($query) => $query->where('is_active', true)
                        ->orWhereIn('id', DepositRule::where('location_id', $location->id)->whereNotNull('service_id')->select('service_id')))
                    ->orderBy('name')
                    ->get()
                : collect(),
            'settings' => $location->effectiveSettings(),
            'salon' => $salon,
            'limits' => (array) config('swayy.deposits'),
            'weekdays' => self::WEEKDAYS,
            'weekdayNames' => self::WEEKDAY_NAMES,
            'canRefunds' => $request->user()?->canInTenant('opening_hours.manage', $tenant, $location) ?? false,
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate($this->ruleRules($request), $this->ruleMessages());
        if (($refusal = $this->foreignBindings($request, $location, $validated)) !== null) {
            return $refusal;
        }

        $rule = DepositRule::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'type' => 'deposit',
            'service_id' => $this->bindingId($validated['service_id'] ?? null),
            'room_id' => $this->bindingId($validated['room_id'] ?? null),
            'weekdays' => $this->weekdaysOf($validated['weekdays'] ?? null),
            'min_party_size' => $this->number($validated['min_party_size'] ?? null),
            'from_time' => $validated['from_time'] ?? null,
            'until_time' => $validated['until_time'] ?? null,
            'amount_per_person_minor' => MoneyInput::toMinor($validated['amount_per_person']),
            'flat_amount_minor' => MoneyInput::toMinor($validated['flat_amount'] ?? 0),
            'currency' => $location->currency,
            'payment_deadline_minutes' => $this->number($validated['payment_deadline_minutes'] ?? null) ?? (int) config('swayy.deposits.deadline_default'),
            'cancel_unpaid_automatically' => $request->boolean('cancel_unpaid_automatically'),
        ]);

        $this->audit->log('deposit_rule.created', $rule, null, $this->snapshot($rule));

        return $this->saved($request, __('Anzahlungsregel „:name“ angelegt.', ['name' => $rule->name]), reload: true);
    }

    public function update(Request $request, DepositRule $rule): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_if($rule->location_id !== $location->id, 404);

        $validated = $request->validate($this->ruleRules($request), $this->ruleMessages());
        if (($refusal = $this->foreignBindings($request, $location, $validated)) !== null) {
            return $refusal;
        }

        $old = $this->snapshot($rule);
        // Was die Anfrage nicht nennt, bleibt: Leistung, Raum, Tage, Mindestzahl an Personen und Bis-Uhrzeit fehlen
        // je nach Betriebsart oder Seite (Salon ohne Personenzahl, alte Sammelseite ohne Tage, Raum und Bis-Uhrzeit),
        // und die bestehende Bindung darf davon nicht verschwinden.
        $rule->update([
            'name' => $validated['name'],
            'service_id' => $request->has('service_id') ? $this->bindingId($validated['service_id'] ?? null) : $rule->service_id,
            'room_id' => $request->has('room_id') ? $this->bindingId($validated['room_id'] ?? null) : $rule->room_id,
            'weekdays' => $request->has('weekdays') ? $this->weekdaysOf($validated['weekdays'] ?? null) : $rule->weekdays,
            'min_party_size' => $request->has('min_party_size') ? $this->number($validated['min_party_size'] ?? null) : $rule->min_party_size,
            'from_time' => $validated['from_time'] ?? null,
            'until_time' => $request->has('until_time') ? ($validated['until_time'] ?? null) : $rule->until_time,
            'amount_per_person_minor' => MoneyInput::toMinor($validated['amount_per_person']),
            'flat_amount_minor' => MoneyInput::toMinor($validated['flat_amount'] ?? 0),
            'payment_deadline_minutes' => $this->number($validated['payment_deadline_minutes'] ?? null) ?? (int) config('swayy.deposits.deadline_default'),
            'cancel_unpaid_automatically' => $request->boolean('cancel_unpaid_automatically'),
        ]);

        $this->audit->log('deposit_rule.updated', $rule, $old, $this->snapshot($rule->refresh()));

        return $this->saved($request, __('Anzahlungsregel „:name“ gespeichert.', ['name' => $rule->name]), reload: true);
    }

    public function destroy(Request $request, DepositRule $rule): mixed
    {
        abort_if($rule->location_id !== $this->context->locationId(), 404);

        // Die Bedingung auf dem Löschen sorgt dafür, dass ein zweiter Auftrag (Doppelklick, zweites Fenster) nichts
        // ins Protokoll schreibt.
        $deleted = DepositRule::whereKey($rule->id)->where('location_id', $rule->location_id)->delete();
        if ($deleted > 0) {
            $this->audit->log('deposit_rule.deleted', $rule, $this->snapshot($rule));
        }

        return $this->saved($request, __('Anzahlungsregel „:name“ gelöscht.', ['name' => $rule->name]), reload: true);
    }

    public function updateRefunds(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(self::refundRules(), self::refundMessages());
        $values = [
            'refund_mode' => $validated['refund_mode'],
            'refund_percent' => (int) $validated['refund_percent'],
            'refund_processing' => $validated['refund_processing'],
        ];

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.refunds_updated', $settings, $old, $values);

        return $this->saved($request, __('Erstattung gespeichert.'));
    }

    /**
     * Prüfregeln einer Anzahlungsregel, mit den Grenzen aus config/swayy.php.
     *
     * @return array<string, list<string>>
     */
    private function ruleRules(Request $request): array
    {
        $limits = (array) config('swayy.deposits');
        $amount = ['regex:'.MoneyInput::PATTERN.'D', 'numeric', 'max:'.(int) $limits['amount_max']];

        return [
            'name' => ['required', 'string', 'max:'.(int) $limits['name_max']],
            'min_party_size' => ['nullable', 'integer', 'min:1', 'max:'.(int) $limits['party_size_max']],
            'amount_per_person' => ['bail', 'required', ...$amount],
            'flat_amount' => ['bail', 'nullable', ...$amount],
            'from_time' => ['nullable', 'date_format:H:i'],
            // Ohne Von-Uhrzeit gibt es nichts zu vergleichen.
            'until_time' => ['nullable', 'date_format:H:i', ...($request->filled('from_time') ? ['after:from_time'] : [])],
            'payment_deadline_minutes' => ['nullable', 'integer', 'min:'.(int) $limits['deadline_min'], 'max:'.(int) $limits['deadline_max']],
            // Die neue Seite sendet den Schalter immer (verstecktes 0 vor dem Kästchen). Die alte Sammelseite sendet ihn nur
            // angehakt; ein fehlender Schalter gilt dort als "aus".
            'cancel_unpaid_automatically' => ['nullable', 'boolean'],
            'service_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'weekdays' => ['nullable', 'array'],
            // Die Seite sendet eine leere Markierung mit, damit ein Formular ohne Haken "an allen Tagen" melden kann.
            'weekdays.*' => ['nullable', 'integer', 'min:0', 'max:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ruleMessages(): array
    {
        $limits = (array) config('swayy.deposits');
        $name = __('Bitte einen Namen für die Regel eintragen, etwa „Gruppen ab 8“.');
        $format = __('Bitte den Betrag in Euro mit Punkt und höchstens zwei Nachkommastellen eintragen, etwa 12.50.');
        $amountMax = __('Der Betrag darf höchstens :amount € sein. Bitte einen kleineren Betrag eintragen.', [
            'amount' => number_format((int) $limits['amount_max'], 0, ',', '.'),
        ]);
        $time = __('Bitte die Uhrzeit im Format 18:30 eintragen.');
        $deadline = __('Bitte die Zahlungsfrist in Minuten als ganze Zahl eintragen, etwa 60.');
        $days = __('Die Auswahl der Wochentage ist ungültig. Bitte die Seite neu laden und die Tage neu wählen.');
        $choice = __('Die Auswahl ist ungültig. Bitte die Seite neu laden und aus der Liste wählen.');

        return [
            'name.required' => $name,
            'name.string' => $name,
            'name.max' => __('Der Name darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
            'min_party_size.integer' => __('Bitte die Personenzahl als ganze Zahl eintragen, etwa 8, oder das Feld leer lassen.'),
            'min_party_size.min' => __('Die Personenzahl muss mindestens :min sein. Bitte eine größere Zahl eintragen oder das Feld leeren.'),
            'min_party_size.max' => __('Die Personenzahl darf höchstens :max sein. Bitte eine kleinere Zahl eintragen.'),
            'amount_per_person.required' => __('Bitte den Betrag pro Person in Euro eintragen, etwa 12.50. Bei einem reinen Grundbetrag steht hier 0.'),
            'amount_per_person.regex' => $format,
            'amount_per_person.numeric' => $format,
            'amount_per_person.max' => $amountMax,
            'flat_amount.regex' => $format,
            'flat_amount.numeric' => $format,
            'flat_amount.max' => $amountMax,
            'from_time.date_format' => $time,
            'until_time.date_format' => $time,
            'until_time.after' => __('„Bis“ liegt vor „Von“. Bitte eine spätere Uhrzeit wählen.'),
            'payment_deadline_minutes.integer' => $deadline,
            'payment_deadline_minutes.min' => __('Die Zahlungsfrist muss mindestens :min Minuten betragen. Bitte eine größere Zahl eintragen.'),
            'payment_deadline_minutes.max' => __('Die Zahlungsfrist darf höchstens :max Minuten betragen. Bitte eine kleinere Zahl eintragen.'),
            'cancel_unpaid_automatically.boolean' => __('Der Schalter für die automatische Stornierung hat einen ungültigen Wert. Bitte die Seite neu laden und noch einmal speichern.'),
            'service_id.integer' => $choice,
            'room_id.integer' => $choice,
            'weekdays.array' => $days,
            'weekdays.*.integer' => $days,
            'weekdays.*.min' => $days,
            'weekdays.*.max' => $days,
        ];
    }

    /**
     * Raum und Leistung müssen zu diesem Standort gehören; eine fremde ID lehnt die Anfrage mit Grund ab.
     *
     * @param  array<string, mixed>  $validated
     */
    private function foreignBindings(Request $request, Location $location, array $validated): mixed
    {
        $errors = [];
        $roomId = $this->bindingId($validated['room_id'] ?? null);
        if ($roomId !== null && ! Room::where('location_id', $location->id)->whereKey($roomId)->exists()) {
            $errors['room_id'] = __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.');
        }
        $serviceId = $this->bindingId($validated['service_id'] ?? null);
        if ($serviceId !== null && ! Service::where('location_id', $location->id)->whereKey($serviceId)->exists()) {
            $errors['service_id'] = __('Diese Leistung gehört nicht zu diesem Standort. Bitte die Seite neu laden und eine Leistung aus der Liste wählen.');
        }

        return $errors === [] ? null : $this->rejected($request, $errors);
    }

    /**
     * Auswahl eines Raums oder einer Leistung; leer und 0 heißen "alle".
     */
    private function bindingId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function number(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Gewählte Wochentage, aufsteigend. Eine leere Auswahl wird null, der Wert für "alle Tage".
     *
     * @return list<int>|null
     */
    private function weekdaysOf(mixed $days): ?array
    {
        $chosen = array_filter((array) $days, fn ($day) => $day !== null && $day !== '');
        $chosen = array_values(array_unique(array_map('intval', $chosen)));
        sort($chosen);

        return $chosen === [] ? null : $chosen;
    }

    /**
     * Die Regel als lesbare Angaben fürs Protokoll.
     *
     * @return array<string, mixed>
     */
    private function snapshot(DepositRule $rule): array
    {
        return [
            'name' => $rule->name,
            'min_party_size' => $rule->min_party_size,
            'amount_per_person' => MoneyInput::fromMinor($rule->amount_per_person_minor),
            'flat_amount' => MoneyInput::fromMinor($rule->flat_amount_minor),
            'from_time' => $rule->from_time ? substr((string) $rule->from_time, 0, 5) : null,
            'until_time' => $rule->until_time ? substr((string) $rule->until_time, 0, 5) : null,
            'weekdays' => $rule->weekdays ? implode(', ', array_map(fn ($day) => self::WEEKDAYS[$day] ?? '?', $rule->weekdays)) : null,
            'payment_deadline_minutes' => $rule->payment_deadline_minutes,
            'cancel_unpaid_automatically' => (bool) $rule->cancel_unpaid_automatically,
            'service_id' => $rule->service_id,
            'room_id' => $rule->room_id,
        ];
    }

    /**
     * Eine Zeile je Regel für die Liste: "12,50 € p. P. · ab 8 Personen · Fr, Sa · ab 18:00 · Raum Terrasse".
     *
     * @param  Collection<int, string>  $roomNames  Name je Raum-ID
     */
    private function summary(DepositRule $rule, bool $salon, Collection $roomNames): string
    {
        $parts = [];
        // Ein Termin im Salon zählt als eine Person; dort heißt der Betrag pro Termin.
        if ($rule->amount_per_person_minor > 0 || $rule->flat_amount_minor === 0) {
            $parts[] = MoneyInput::format($rule->amount_per_person_minor).' '.($salon ? __('pro Termin') : __('p. P.'));
        }
        if ($rule->flat_amount_minor > 0) {
            $parts[] = MoneyInput::format($rule->flat_amount_minor).' '.__('Grundbetrag');
        }
        if ($rule->min_party_size && ! $salon) {
            $parts[] = trans_choice('ab :count Person|ab :count Personen', $rule->min_party_size);
        }
        if (! empty($rule->weekdays)) {
            $parts[] = implode(', ', array_map(fn ($day) => self::WEEKDAYS[$day] ?? '?', $rule->weekdays));
        }
        if ($rule->from_time) {
            $parts[] = __('ab :time', ['time' => substr((string) $rule->from_time, 0, 5)]);
        }
        if ($rule->until_time) {
            $parts[] = __('bis :time', ['time' => substr((string) $rule->until_time, 0, 5)]);
        }
        if ($rule->room_id && $roomNames->has($rule->room_id)) {
            $parts[] = __('Raum :name', ['name' => $roomNames->get($rule->room_id)]);
        }
        if ($rule->service) {
            $parts[] = __('Leistung :name', ['name' => $rule->service->name]);
        }
        if (! $rule->cancel_unpaid_automatically) {
            $parts[] = __('Auto-Storno aus');
        }

        return implode(' · ', $parts);
    }
}

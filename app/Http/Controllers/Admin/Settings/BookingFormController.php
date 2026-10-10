<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\LocationSettings;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsformular: welche Felder Gäste beim
 * Buchen sehen und welche davon Pflicht sind. Den Namen fragt das Formular
 * immer ab. Ein Pflichtfeld erzwingt die Buchungsseite nur bei E-Mail und
 * Telefon; Anlass, Allergien und Anmerkung erscheinen oder bleiben
 * ausgeblendet (PublicBookingController::store). Salons stellen die Anmerkung
 * ein: Die Buchung eines Termins verlangt die E-Mail-Adresse immer, fragt das
 * Telefon freiwillig ab und kennt weder Anlass noch Allergien
 * (PublicBookingController::storeSalon). Gilt für den gewählten Standort.
 */
class BookingFormController extends Controller
{
    use RespondsToSettingsForms;

    /** Felder der Buchungsseite für Restaurants, die der Betrieb einstellt (LocationSettings::fieldRule). */
    public const FIELDS = ['email', 'phone', 'occasion', 'allergies', 'note'];

    /** Felder, die das Formular für Termine einstellbar macht. */
    public const SALON_FIELDS = ['note'];

    /** @var array<string, string> Wert => Bezeichnung */
    public const CHOICES = ['hidden' => 'Ausgeblendet', 'optional' => 'Freiwillig', 'required' => 'Pflichtfeld'];

    /**
     * Auswahl je Feld: Was die Buchungsseite umsetzt, steht zur Wahl. Ein
     * Pflichtfeld erzwingt sie nur bei E-Mail und Telefon.
     *
     * @var array<string, list<string>> Feld => Werte aus CHOICES
     */
    public const FIELD_CHOICES = [
        'email' => ['hidden', 'optional', 'required'],
        'phone' => ['hidden', 'optional', 'required'],
        'occasion' => ['hidden', 'optional'],
        'allergies' => ['hidden', 'optional'],
        'note' => ['hidden', 'optional'],
    ];

    /** Wert, den ein Feld zeigt, wenn der gespeicherte Wert nicht zur Auswahl gehört (wie LocationSettings::fieldRule bei unbekannten Feldern). */
    private const FALLBACK_CHOICE = 'optional';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $salon = $this->context->tenant()->isSalon();
        $settings = $location->effectiveSettings();
        $fields = $salon ? self::SALON_FIELDS : self::FIELDS;
        $choices = $this->choicesOf($fields);

        return view('admin.settings.booking-form', [
            'salon' => $salon,
            'fields' => $fields,
            'choices' => $choices,
            'rules' => $this->shownRules($settings, $choices),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $fields = $this->context->tenant()->isSalon() ? self::SALON_FIELDS : self::FIELDS;

        // Ein Formular aus einem älteren Stand der Seite (etwa vor einem Wechsel
        // des Betriebstyps) sendet andere Felder: eine Meldung für das ganze Formular.
        if (array_diff($fields, array_keys((array) $request->input('fields', []))) !== []) {
            return $this->failed($request, 'fields', __('Das Formular ist unvollständig: Es fehlen Felder, etwa weil sich der Betriebstyp seit dem Laden der Seite geändert hat. Bitte die Seite neu laden und erneut speichern.'));
        }

        // Gespeichert wird jeder Wert aus CHOICES, damit die alte Sammelseite weiter speichert.
        $validated = $request->validate(
            collect($fields)
                ->mapWithKeys(fn (string $field) => ["fields.{$field}" => ['required', 'in:'.implode(',', array_keys(self::CHOICES))]])
                ->all(),
            $this->messages($fields),
        );

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = collect($fields)->mapWithKeys(fn (string $field) => [$field => $settings->fieldRule($field)])->all();
        // Regeln, die dieses Formular nicht kennt, bleiben stehen.
        $settings->update(['field_rules' => array_merge((array) $settings->field_rules, $validated['fields'])]);

        $this->audit->log('location.field_rules_updated', $settings, $old, $validated['fields']);

        return $this->saved($request, __('Formularfelder gespeichert.'));
    }

    /**
     * Die Auswahl je Feld mit Bezeichnungen: Wert => Bezeichnung.
     *
     * @param  list<string>  $fields
     * @return array<string, array<string, string>>
     */
    private function choicesOf(array $fields): array
    {
        $choices = [];
        foreach ($fields as $field) {
            $choices[$field] = array_intersect_key(self::CHOICES, array_flip(self::FIELD_CHOICES[$field]));
        }

        return $choices;
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Werte, die das Feld anbietet, und
     * den nächsten Schritt.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function messages(array $fields): array
    {
        $messages = [];
        foreach ($this->choicesOf($fields) as $field => $offered) {
            $text = __('Bitte für :attribute :choices wählen.', [
                'choices' => Arr::join(array_map(fn (string $label) => "„{$label}“", $offered), ', ', ' oder '),
            ]);
            $messages["fields.{$field}.required"] = $text;
            $messages["fields.{$field}.in"] = $text;
        }

        return $messages;
    }

    /**
     * Die Auswahl je Feld: der zuletzt gesendete Wert nach einer Ablehnung,
     * sonst der gespeicherte. Gehört der Wert nicht zur Auswahl des Feldes,
     * zeigt es den Wert, den die Buchungsseite tatsächlich anwendet.
     *
     * @param  array<string, array<string, string>>  $choices  Feld => (Wert => Bezeichnung)
     * @return array<string, string>
     */
    private function shownRules(LocationSettings $settings, array $choices): array
    {
        $shown = [];
        foreach ($choices as $field => $offered) {
            $sent = old("fields.{$field}");
            $value = is_string($sent) && array_key_exists($sent, $offered) ? $sent : $settings->fieldRule($field);
            $shown[$field] = array_key_exists($value, $offered) ? $value : self::FALLBACK_CHOICE;
        }

        return $shown;
    }
}

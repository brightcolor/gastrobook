<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\LocationSettings;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsformular: welche Felder Gäste beim
 * Buchen sehen und welche davon Pflicht sind. Den Namen fragt das Formular
 * immer ab. Salons stellen die Anmerkung ein: Die Buchung eines Termins
 * verlangt die E-Mail-Adresse immer, fragt das Telefon freiwillig ab und kennt
 * weder Anlass noch Allergien (PublicBookingController::storeSalon). Gilt für
 * den gewählten Standort.
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
     * Auswahl für Termine: Die Buchung erzwingt kein Pflichtfeld, die Anmerkung
     * erscheint oder bleibt ausgeblendet.
     *
     * @var array<string, string> Wert => Bezeichnung
     */
    public const SALON_CHOICES = ['hidden' => 'Ausgeblendet', 'optional' => 'Freiwillig'];

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
        $choices = $salon ? self::SALON_CHOICES : self::CHOICES;

        return view('admin.settings.booking-form', [
            'salon' => $salon,
            'fields' => $fields,
            'choices' => $choices,
            'rules' => $this->shownRules($settings, $fields, $choices),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $fields = $this->context->tenant()->isSalon() ? self::SALON_FIELDS : self::FIELDS;

        $validated = $request->validate(
            collect($fields)
                ->mapWithKeys(fn (string $field) => ["fields.{$field}" => ['required', 'in:'.implode(',', array_keys(self::CHOICES))]])
                ->all()
        );

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = collect($fields)->mapWithKeys(fn (string $field) => [$field => $settings->fieldRule($field)])->all();
        // Regeln, die dieses Formular nicht kennt, bleiben stehen.
        $settings->update(['field_rules' => array_merge((array) $settings->field_rules, $validated['fields'])]);

        $this->audit->log('location.field_rules_updated', $settings, $old, $validated['fields']);

        return $this->saved($request, __('Formularfelder gespeichert.'));
    }

    /**
     * Die Auswahl je Feld: der zuletzt gesendete Wert nach einer Ablehnung,
     * sonst der gespeicherte. Gehört der Wert nicht zur Auswahl der Seite,
     * zeigt das Feld den Wert, den die Buchungsseite tatsächlich anwendet.
     *
     * @param  list<string>  $fields
     * @param  array<string, string>  $choices
     * @return array<string, string>
     */
    private function shownRules(LocationSettings $settings, array $fields, array $choices): array
    {
        $shown = [];
        foreach ($fields as $field) {
            $sent = old("fields.{$field}");
            $value = is_string($sent) && array_key_exists($sent, self::CHOICES) ? $sent : $settings->fieldRule($field);
            $shown[$field] = array_key_exists($value, $choices) ? $value : self::FALLBACK_CHOICE;
        }

        return $shown;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\OpeningHour;
use App\Models\SeasonPeriod;
use App\Services\AuditLogger;
use App\Support\OpeningHoursPreview;
use App\Support\OpeningHoursRules;
use App\Support\ScheduleExceptionRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Öffnungszeiten: die reguläre Woche mit
 * ihren Zeitfenstern, die Vorschau „So sehen Gäste es“ und die buchbare
 * Saison. Gilt für den gewählten Standort. Die Speicherwege
 * (admin.settings.opening-hours, seasons.*) nutzt auch der
 * Einrichtungsassistent; ihre Antworten behalten deshalb ihre Felder.
 */
class OpeningHoursController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly OpeningHoursPreview $preview,
    ) {}

    public function show(Request $request): View
    {
        $location = $this->context->location();
        $tenant = $this->context->tenant();
        abort_if($location === null || $tenant === null, 404);
        $salon = $tenant->isSalon();

        // Jedes Zeitfenster trägt einen Schlüssel für sein Formularfeld, über alle Tage fortlaufend.
        $key = 0;
        $byDay = [];
        foreach ($location->openingHours()->orderBy('weekday')->orderBy('opens_at')->get() as $hour) {
            $byDay[(int) $hour->weekday][] = [
                'key' => $key++,
                'opens_at' => substr((string) $hour->opens_at, 0, 5),
                'closes_at' => substr((string) $hour->closes_at, 0, 5),
                'service_name' => $hour->service_name,
            ];
        }
        $days = [];
        foreach (OpeningHoursPreview::WEEKDAYS as $weekday => $name) {
            $days[] = ['weekday' => $weekday, 'name' => $name, 'windows' => $byDay[$weekday] ?? []];
        }

        return view('admin.settings.opening-hours', [
            'days' => $days,
            'seasons' => $location->seasonPeriods()->get(),
            'months' => SeasonPeriod::months(),
            'limits' => (array) config('swayy.opening_hours'),
            'previewDelay' => (int) config('swayy.ui.preview_delay_ms'),
            'duration' => $this->preview->duration($location, $salon),
            'salon' => $salon,
            'canSeasons' => $request->user()->canInTenant('blackouts.manage', $tenant, $location),
            'canExceptions' => ScheduleExceptionRules::canManage($request->user(), $tenant, $location),
        ]);
    }

    /**
     * Die Woche ersetzt die gespeicherte Liste als Ganzes: Zeilen, die das
     * Formular nicht mehr sendet, sind entfernt. Eine Woche ohne offenen Tag
     * sendet keine Zeile; das Kennzeichen hours_sent des Formulars macht
     * daraus die leere Liste. Fehlt auch das Kennzeichen, gilt die Anfrage
     * als unvollständig und der gespeicherte Stand bleibt.
     */
    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if (! $request->has('hours') && $request->boolean('hours_sent')) {
            $request->merge(['hours' => []]);
        }
        $hours = $this->checked($request, OpeningHoursRules::rules(), 'hours')['hours'];

        DB::transaction(function () use ($location, $hours) {
            // Die Sperre auf den Standort lässt zwei gleichzeitige Speicherungen nacheinander laufen.
            $location->lockRow();
            $location->openingHours()->delete();
            foreach ($hours as $hour) {
                OpeningHour::create([
                    'tenant_id' => $location->tenant_id,
                    'location_id' => $location->id,
                    'weekday' => (int) $hour['weekday'],
                    'opens_at' => $hour['opens_at'],
                    'closes_at' => $hour['closes_at'],
                    'service_name' => $hour['service_name'] ?? null,
                ]);
            }
        });

        $this->audit->log('opening_hours.updated', null, null, ['count' => count($hours)]);

        return $this->saved($request, __('Öffnungszeiten gespeichert.'));
    }

    /**
     * Vorschau für einen Wochentag mit den Zeitfenstern aus dem Formular, auch
     * ungespeichert. Schreibt nichts.
     */
    public function preview(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $this->checked($request, OpeningHoursRules::windowRules(), 'windows', sameDay: true);

        return response()->json($this->preview->forDay($location, (int) $validated['weekday'], array_values($validated['windows'])));
    }

    public function storeSeason(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(OpeningHoursRules::seasonRules(), OpeningHoursRules::seasonMessages());

        // Den Tag gegen den Monat prüfen; 2024 ist ein Schaltjahr, damit der
        // 29. Februar erlaubt bleibt.
        if (! checkdate((int) $validated['start_month'], (int) $validated['start_day'], 2024)) {
            return $this->failed($request, 'start_day', __('Diesen Tag gibt es in dem Monat nicht. Bitte einen gültigen Tag wählen.'));
        }
        if (! checkdate((int) $validated['end_month'], (int) $validated['end_day'], 2024)) {
            return $this->failed($request, 'end_day', __('Diesen Tag gibt es in dem Monat nicht. Bitte einen gültigen Tag wählen.'));
        }

        $season = $location->seasonPeriods()->create([
            'tenant_id' => $location->tenant_id,
            'label' => $validated['label'] ?? null,
            'start_month' => (int) $validated['start_month'],
            'start_day' => (int) $validated['start_day'],
            'end_month' => (int) $validated['end_month'],
            'end_day' => (int) $validated['end_day'],
        ]);

        $this->audit->log('season.created', $season, null, $validated);

        return $this->saved($request, __('Saison gespeichert.'), true);
    }

    public function deleteSeason(SeasonPeriod $season, Request $request): mixed
    {
        abort_if($season->location_id !== $this->context->locationId(), 404);
        $this->audit->log('season.deleted', $season, ['label' => $season->label]);
        $season->delete();

        return $this->saved($request, __('Saison gelöscht.'), true);
    }

    /**
     * Prüft die Fenster mit den Regeln der einzelnen Felder und danach gegeneinander
     * (OpeningHoursRules::after). Beide Arten von Fehlern kommen in einer Antwort.
     *
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function checked(Request $request, array $rules, string $prefix, bool $sameDay = false): array
    {
        $input = $request->all();
        $validator = validator($input, $rules, OpeningHoursRules::messages($prefix));
        $validator->after(function (Validator $validator) use ($input, $prefix, $sameDay) {
            foreach (OpeningHoursRules::after((array) ($input[$prefix] ?? []), $prefix, $sameDay) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        return $validator->validate();
    }
}

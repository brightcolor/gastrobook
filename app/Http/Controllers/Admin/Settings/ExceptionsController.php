<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\ScheduleExceptions;
use App\Support\ScheduleExceptionRules;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Einstellungen › Platz & Zeiten › Ausnahmen: Anlegen, Ändern, Löschen und
 * die Vorschau „So wirkt es“ für den gewählten Standort. Die Seite zeigt die
 * Liste und das Formular; hier liegen die Speicherwege. Sie verlangen die
 * Rechte für Sondertage und für Sperrzeiten, weil eine Ausnahme beides
 * schreiben kann.
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
        $before = $this->exceptions->find($location, $key);
        abort_if($before === null, 404);
        $data = $this->checked($request, $location);

        $result = $this->exceptions->save($location, $data, $key, $request->user()?->id);
        $this->audit->log('exception.updated', null, $this->values($before), $this->values($result['entry']));

        return $this->saved($request, $this->message($result['overlapping']), true);
    }

    public function destroy(Request $request, string $key): mixed
    {
        $location = $this->location();
        $before = $this->exceptions->find($location, $key);
        abort_if($before === null, 404);

        abort_unless($this->exceptions->delete($location, $key), 404);
        $this->audit->log('exception.deleted', null, $this->values($before));

        return $this->saved($request, __('Ausnahme gelöscht.'), true);
    }

    /**
     * Der Satz „So wirkt es“ zu den Feldern des Formulars, auch ungespeichert.
     * Schreibt nichts.
     */
    public function preview(Request $request): mixed
    {
        $location = $this->location();
        $data = $this->checked($request, $location);

        return response()->json(['sentence' => $this->exceptions->describe($location, $data)]);
    }

    private function location(): Location
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return $location;
    }

    /**
     * Prüft die Felder einzeln (ScheduleExceptionRules::rules) und danach
     * gegeneinander und gegen den Standort (ScheduleExceptionRules::after).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(Request $request, Location $location): array
    {
        $salon = (bool) $this->context->tenant()?->isSalon();
        $data = $request->validate(ScheduleExceptionRules::rules($salon), ScheduleExceptionRules::messages($salon));

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

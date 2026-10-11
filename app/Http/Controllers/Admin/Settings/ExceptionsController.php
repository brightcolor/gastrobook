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
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Provisioning;

use App\Http\Controllers\Controller;
use App\Provisioning\Idempotency;
use App\Provisioning\Outcome;
use App\Provisioning\ProvisioningError;
use App\Provisioning\ProvisioningService;
use App\Provisioning\RequestCheck;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Die Bereitstellungs-Schnittstelle fuer das Kundenpanel unter /provisioning/v1.
 *
 * Jeder Aufruf: Pruefung (RequestCheck) → JSON-Objekt → bei aendernden
 * Aufrufen die Kennung → Arbeit in einer Transaktion samt Kennung → danach
 * Mails. Eine Ablehnung rollt alles zurueck, die Kennung eingeschlossen.
 */
class ProvisioningController extends Controller
{
    private const ACTIONS = ['approve', 'suspend', 'unsuspend', 'cancel', 'uncancel', 'login-link'];

    public function __construct(
        private readonly RequestCheck $check,
        private readonly Idempotency $idempotency,
        private readonly ProvisioningService $service,
    ) {}

    public function info(Request $request): Response|JsonResponse
    {
        return $this->call($request, fn () => $this->service->info(), changing: false);
    }

    public function create(Request $request): Response|JsonResponse
    {
        return $this->call($request, fn (array $payload) => $this->service->createAccount($payload), changing: true);
    }

    public function status(Request $request, string $contract): Response|JsonResponse
    {
        return $this->call($request, fn () => $this->service->status($contract), changing: false);
    }

    public function action(Request $request, string $contract, string $action): Response|JsonResponse
    {
        return $this->call($request, function (array $payload) use ($contract, $action) {
            return match ($action) {
                'approve' => $this->service->approve($contract, $payload),
                'suspend' => $this->service->suspend($contract, $payload),
                'unsuspend' => $this->service->unsuspend($contract, $payload),
                'cancel' => $this->service->cancel($contract, $payload),
                'uncancel' => $this->service->uncancel($contract, $payload),
                'login-link' => $this->service->loginLink($contract, $payload),
                default => throw new ProvisioningError(404, 'unknown_action', '„'.mb_substr($action, 0, 40).'“ kennt die Schnittstelle nicht. Möglich sind '.implode(', ', self::ACTIONS).'.'),
            };
        }, changing: true);
    }

    public function tariff(Request $request, string $contract): Response|JsonResponse
    {
        return $this->call($request, fn (array $payload) => $this->service->setTariff($contract, $payload), changing: true);
    }

    public function delete(Request $request, string $contract): Response|JsonResponse
    {
        return $this->call($request, fn (array $payload) => $this->service->deleteAccount($contract, $payload), changing: true);
    }

    /**
     * @param  callable(array<string, mixed>): Outcome  $work
     */
    private function call(Request $request, callable $work, bool $changing): Response|JsonResponse
    {
        try {
            $this->check->check($request);
            $payload = $this->payload($request->getContent());

            $schluessel = null;
            if ($changing) {
                $schluessel = (string) $request->header('Idempotency-Key', '');
                if (preg_match(Idempotency::KEY_PATTERN, $schluessel) !== 1) {
                    throw new ProvisioningError(400, 'idempotency_key', 'Jeder ändernde Aufruf braucht einen Idempotency-Key aus 1 bis 100 Zeichen: Buchstaben, Ziffern und . _ : -');
                }
            }
            $pruefsumme = Idempotency::requestHash($request->getMethod(), $request->getRequestUri(), $request->getContent());

            $ergebnis = DB::transaction(function () use ($work, $payload, $schluessel, $pruefsumme) {
                $eintrag = null;
                if ($schluessel !== null) {
                    $eintrag = $this->idempotency->claim($schluessel, $pruefsumme);
                    if ($eintrag['replay']) {
                        return new Outcome($eintrag['status'], $eintrag['response']);
                    }
                }
                $outcome = $work($payload);
                if ($eintrag !== null && $eintrag['id'] !== null) {
                    $this->idempotency->finish($eintrag['id'], $outcome->status, $outcome->body);
                }

                return $outcome;
            });
        } catch (ProvisioningError $fehler) {
            return response()->json($fehler->body(), $fehler->status);
        } catch (QueryException $fehler) {
            // Die Transaktion ist zurueckgerollt, Kennung eingeschlossen. 5xx
            // gilt im Vertrag als voruebergehend: Das Panel wiederholt den
            // Aufruf nach Plan, erst nach dem letzten Versuch wird es ein Fall.
            report($fehler);
            $antwort = new ProvisioningError(503, 'database_unavailable', 'Swayy konnte den Aufruf gerade nicht in seiner Datenbank festhalten; es wurde nichts geändert. Wiederhole ihn später mit derselben Kennung. Bleibt der Fehler, steht die Ursache im Serverlog von Swayy.');

            return response()->json($antwort->body(), $antwort->status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($ergebnis->afterCommit as $schritt) {
            $schritt();
        }

        return $ergebnis->status === 204
            ? response()->noContent()
            : response()->json($ergebnis->body, $ergebnis->status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        if ($body === '') {
            return [];
        }
        // Ein Objekt beginnt mit {; [] dekodierte sonst ebenfalls zu einem leeren Array.
        $daten = json_decode($body, true);
        if (! str_starts_with(ltrim($body), '{') || ! is_array($daten)) {
            throw new ProvisioningError(400, 'invalid_json', 'Der Inhalt des Aufrufs muss ein JSON-Objekt sein, etwa {} für einen Aufruf ohne Daten.');
        }

        return $daten;
    }
}

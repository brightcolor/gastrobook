<?php

declare(strict_types=1);

namespace App\Provisioning;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Prueft einen Aufruf des Panels: Schluessel, Netz, Drossel, Koepfe,
 * Zeitstempel und Signatur, in dieser Reihenfolge (Vertrag, Signatur).
 *
 * GEHASHT WIRD, WAS ANKAM: der Koerper als Bytes vor jedem JSON-Parsen und der
 * Pfad mit Query, wie er auf dem Draht steht. Ein neu kodierter Koerper haette
 * eine andere Reihenfolge oder andere Escapes, und keine Signatur passte.
 *
 * Signaturen und Schluessel erscheinen in keiner Meldung und keinem Log.
 */
final class RequestCheck
{
    private const THROTTLE_PREFIX = 'provisioning:';

    public function check(Request $request, ?int $now = null): void
    {
        $schluessel = (array) config('provisioning.keys', []);
        if ($schluessel === []) {
            throw new ProvisioningError(404, 'interface_off', 'Die Schnittstelle für den Kundenbereich ist ausgeschaltet. Trag PROVISIONING_KEYS in die .env von swayy ein und starte die App neu.');
        }

        $absender = (string) $request->ip();
        $netze = (array) config('provisioning.allowed_networks', []);
        if ($netze !== [] && ! IpUtils::checkIp($absender, $netze)) {
            throw new ProvisioningError(403, 'network', "Aufrufe von {$absender} sind nicht erlaubt. Ergänze das Netz in PROVISIONING_ALLOWED_NETWORKS oder rufe aus einem erlaubten Netz auf.");
        }

        $drossel = self::THROTTLE_PREFIX.$absender;
        if (RateLimiter::tooManyAttempts($drossel, (int) config('provisioning.max_failures', 10))) {
            $sekunden = RateLimiter::availableIn($drossel);
            throw new ProvisioningError(429, 'throttled', "Von {$absender} kamen zu viele Aufrufe mit falscher Signatur. Warte {$sekunden} Sekunden und prüf den Schlüssel im Kundenbereich.");
        }

        $stempel = (string) $request->header('X-Provisioning-Timestamp', '');
        $gegeben = (string) $request->header('X-Provisioning-Signature', '');
        if (! ctype_digit($stempel) || strlen($stempel) > 12 || ! str_starts_with($gegeben, Signature::SCHEME)) {
            $this->failed($drossel);
            throw new ProvisioningError(401, 'signature_missing', 'Zeitstempel oder Signatur fehlen. Der Kundenbereich schickt X-Provisioning-Timestamp und X-Provisioning-Signature mit jedem Aufruf.');
        }

        $abweichung = (int) config('provisioning.max_skew_seconds', 300);
        if (abs(($now ?? time()) - (int) $stempel) > $abweichung) {
            throw new ProvisioningError(401, 'timestamp', "Der Zeitstempel weicht mehr als {$abweichung} Sekunden von der Uhr von swayy ab. Prüf die Uhrzeit beider Server.");
        }

        $wert = substr($gegeben, strlen(Signature::SCHEME));
        $ziel = $request->getRequestUri();
        $koerper = $request->getContent();
        foreach ($schluessel as $key) {
            if (hash_equals(Signature::request((string) $key, $stempel, $request->getMethod(), $ziel, $koerper), $wert)) {
                RateLimiter::clear($drossel);

                return;
            }
        }

        $this->failed($drossel);
        throw new ProvisioningError(401, 'signature', 'Die Signatur passt zu keinem Schlüssel von swayy. Vergleich den Schlüssel im Kundenbereich mit PROVISIONING_KEYS.');
    }

    private function failed(string $drossel): void
    {
        RateLimiter::hit($drossel, (int) config('provisioning.lock_seconds', 900));
    }
}

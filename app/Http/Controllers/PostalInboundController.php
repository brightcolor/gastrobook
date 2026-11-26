<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Mail\InboundGuestReplyRouter;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\PostalSignatureVerifier;
use Illuminate\Http\Request;

/**
 * Nimmt eingehende Gästeantworten von Postal an (Eingangsroute → HTTP).
 * CSRF-ausgenommen; die Echtheit sichert die Postal-Signatur, nicht die Session.
 * Verständliche Meldungen mit Status 400/401/500, im Fehlerfall ein Protokoll.
 */
class PostalInboundController extends Controller
{
    public function __construct(
        private readonly PostalSignatureVerifier $verifier,
        private readonly InboundGuestReplyRouter $router,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();

        $rejection = $this->verifier->rejection($request);
        if ($rejection !== null) {
            return response()->json(['error' => $rejection], 401);
        }

        $data = json_decode($payload, true);
        $rcpt = is_array($data) ? ($data['rcpt_to'] ?? null) : null;
        $encoded = is_array($data) ? ($data['message'] ?? null) : null;
        if (! is_string($rcpt) || ! is_string($encoded)) {
            return response()->json(['error' => 'Die Anfrage enthält keine lesbare Nachricht (Felder rcpt_to und message erwartet).'], 400);
        }

        $maxBytes = (int) config('swayy.guest_mail_relay.max_message_bytes');
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) > $maxBytes) {
            return response()->json(['error' => 'Die Nachricht fehlt oder ist größer als erlaubt.'], 400);
        }

        try {
            $this->router->route(
                recipient: $rcpt,
                mailFrom: (string) ($data['mail_from'] ?? ''),
                postalMessageId: (string) ($data['id'] ?? ($data['message_id'] ?? md5($raw))),
                message: InboundMessage::fromRaw($raw),
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Die Nachricht konnte nicht verarbeitet werden. Der Fall wurde protokolliert.'], 500);
        }

        return response()->json(['received' => true]);
    }
}

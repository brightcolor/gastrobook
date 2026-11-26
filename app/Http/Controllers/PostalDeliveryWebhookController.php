<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Mail\MailDeliveryLog;
use App\Services\Mail\PostalSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nimmt die Zustellmeldungen von Postal an (Webhook des Mailservers):
 * MessageSent, MessageDelayed, MessageDeliveryFailed, MessageHeld,
 * MessageBounced. Andere Ereignisse beantwortet er mit 200, damit Postal sie
 * nicht wiederholt.
 *
 * CSRF-ausgenommen; die Echtheit sichert die Postal-Signatur.
 */
class PostalDeliveryWebhookController extends Controller
{
    public function __construct(
        private readonly PostalSignatureVerifier $verifier,
        private readonly MailDeliveryLog $deliveryLog,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rejection = $this->verifier->rejection($request);
        if ($rejection !== null) {
            return response()->json(['error' => $rejection], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (! is_array($data) || ! is_string($data['event'] ?? null)) {
            return response()->json(['error' => 'Die Anfrage enthält keine lesbare Meldung (Feld event erwartet).'], 400);
        }

        return response()->json(['result' => $this->deliveryLog->applyPostalEvent($data)]);
    }
}

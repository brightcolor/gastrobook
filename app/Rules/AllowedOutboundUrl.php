<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Zielpruefung fuer Adressen, die der Server spaeter selbst aufruft
 * (Webhook-Endpunkte, MailWizz-API): https, ein aufloesbarer Name und nur
 * erlaubte Adressen dahinter. Die Meldung nennt den Grund und den naechsten
 * Schritt.
 *
 * Beim Speichern allein reicht das nicht - ein Name kann spaeter auf eine
 * andere Adresse zeigen. Deshalb prueft jeder Aufruf noch einmal
 * (OutboundUrlGuard::client(), DeliverWebhook).
 */
final class AllowedOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(OutboundUrlBlocked::because(OutboundUrlBlocked::INVALID)->getMessage());

            return;
        }

        try {
            OutboundUrlGuard::check($value);
        } catch (OutboundUrlBlocked $e) {
            $fail($e->getMessage());
        }
    }
}

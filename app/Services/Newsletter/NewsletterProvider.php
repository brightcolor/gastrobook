<?php

namespace App\Services\Newsletter;

use App\Models\Guest;
use App\Support\OutboundUrlBlocked;

/**
 * Adapter interface for newsletter systems (MailWizz, Mailchimp, Brevo, …).
 * Implementations must be idempotent: subscribing an existing address
 * updates it instead of failing.
 *
 * Adressen, die der Betrieb selbst eintraegt, gehen durch die Zielpruefung
 * (App\Support\OutboundUrlGuard). Lehnt sie ab, werfen die Methoden
 * OutboundUrlBlocked, ohne eine Anfrage zu senden.
 */
interface NewsletterProvider
{
    /**
     * @return bool true when the subscriber was accepted by the provider
     *
     * @throws OutboundUrlBlocked
     */
    public function subscribe(Guest $guest): bool;

    /**
     * @throws OutboundUrlBlocked
     */
    public function unsubscribe(Guest $guest): bool;
}

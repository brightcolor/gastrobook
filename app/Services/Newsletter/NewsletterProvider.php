<?php

namespace App\Services\Newsletter;

use App\Models\Guest;

/**
 * Adapter interface for newsletter systems (MailWizz, Mailchimp, Brevo, …).
 * Implementations must be idempotent: subscribing an existing address
 * updates it instead of failing.
 */
interface NewsletterProvider
{
    /**
     * @return bool true when the subscriber was accepted by the provider
     */
    public function subscribe(Guest $guest): bool;

    public function unsubscribe(Guest $guest): bool;
}

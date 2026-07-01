<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\TemplatedMail;
use App\Models\BillingProfile;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Payments\GoCardlessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Receives asynchronous GoCardless events (payment confirmed/failed, mandate
 * cancelled/expired) and notifies both the customer and the platform owner.
 * CSRF-exempt; authenticated via HMAC signature, not session.
 */
class GoCardlessWebhookController extends Controller
{
    public function __construct(
        private readonly GoCardlessService $gocardless,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();

        if (! $this->gocardless->verifyWebhookSignature($payload, (string) $request->header('Webhook-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $events = json_decode($payload, true);
        if (! is_array($events) || ! isset($events['events']) || ! is_array($events['events'])) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        foreach ($events['events'] as $event) {
            if (is_array($event) && $this->markSeen($event)) {
                $this->handleEvent($event);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Record the GoCardless event id so a redelivered/replayed webhook is not
     * processed twice (duplicate notification mails, stale status overwrites).
     * Returns false when the event was already seen.
     *
     * @param  array<string,mixed>  $event
     */
    private function markSeen(array $event): bool
    {
        $eventId = $event['id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            // No id to dedupe on — process it (better than silently dropping).
            return true;
        }

        try {
            DB::table('gocardless_webhook_events')->insert(['event_id' => $eventId, 'created_at' => now()]);

            return true;
        } catch (QueryException) {
            // Unique constraint violation = already processed.
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function handleEvent(array $event): void
    {
        $mandateId = $event['links']['mandate'] ?? null;
        if (! is_string($mandateId) || $mandateId === '') {
            return;
        }

        $profile = BillingProfile::where('gocardless_mandate_id', $mandateId)->first();
        if ($profile === null) {
            return;
        }

        $tenant = Tenant::find($profile->tenant_id);
        if ($tenant === null) {
            return;
        }

        $resource = (string) ($event['resource_type'] ?? '');
        $action = (string) ($event['action'] ?? '');

        [$subject, $body, $statusUpdate] = match (true) {
            $resource === 'payments' && $action === 'failed' => [
                __('Lastschrift fehlgeschlagen – :tenant', ['tenant' => $tenant->name]),
                __("Hallo,\n\neine SEPA-Lastschrift für „:tenant\" ist fehlgeschlagen. Bitte prüfe deine Kontodaten oder Deckung; GoCardless versucht es ggf. erneut.", ['tenant' => $tenant->name]),
                ['payment_status' => 'past_due'],
            ],
            $resource === 'payments' && $action === 'confirmed' => [
                __('Zahlung eingegangen – :tenant', ['tenant' => $tenant->name]),
                __("Hallo,\n\ndie SEPA-Lastschrift für „:tenant\" wurde erfolgreich eingezogen. Vielen Dank!", ['tenant' => $tenant->name]),
                ['payment_status' => 'active'],
            ],
            $resource === 'mandates' && in_array($action, ['cancelled', 'failed', 'expired'], true) => [
                __('Lastschriftmandat beendet – :tenant', ['tenant' => $tenant->name]),
                __("Hallo,\n\ndas SEPA-Lastschriftmandat für „:tenant\" ist nicht mehr aktiv (:action). Es erfolgen keine weiteren Abbuchungen, bis ein neues Mandat eingerichtet wird.", ['tenant' => $tenant->name, 'action' => $action]),
                ['gocardless_status' => 'cancelled', 'gocardless_subscription_id' => null, 'payment_status' => 'cancelled'],
            ],
            default => [null, null, null],
        };

        if ($subject === null) {
            return; // event we don't act on
        }

        $profile->update($statusUpdate);

        $this->audit->log('billing.directdebit.webhook', $tenant, null, [
            'resource' => $resource, 'action' => $action,
        ], null, null, $tenant->id);

        $this->notifyBoth($tenant, $profile, $subject, $body);
    }

    private function notifyBoth(Tenant $tenant, BillingProfile $profile, string $subject, string $body): void
    {
        $owner = config('swayy.owner_email')
            ?: config('services.support_email')
            ?: config('mail.from.address');

        foreach (array_unique(array_filter([$profile->billing_email, $owner])) as $to) {
            Mail::to($to)->queue(new TemplatedMail($subject, $body));
        }
    }
}

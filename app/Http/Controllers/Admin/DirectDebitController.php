<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\BillingProfile;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Payments\GoCardlessService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DirectDebitController extends Controller
{
    private const SESSION_KEY = 'gc_redirect_session_token';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly GoCardlessService $gocardless,
    ) {}

    public function show()
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        return view('admin.billing.index', [
            'tenant' => $tenant,
            'plan' => $tenant->plan,
            'profile' => $tenant->billingProfile,
            'configured' => $this->gocardless->configured(),
        ]);
    }

    /**
     * Start a mandate setup: create a GoCardless redirect flow and send the
     * customer to the hosted authorisation page.
     */
    public function setup(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        if (! $this->gocardless->configured()) {
            return back()->withErrors(['billing' => __('Lastschrift ist derzeit nicht verfügbar.')]);
        }

        $plan = $tenant->plan;
        if ($plan === null || (int) $plan->price_monthly_minor <= 0) {
            return back()->withErrors(['billing' => __('Für diesen Tarif ist keine Lastschrift möglich.')]);
        }

        $existing = BillingProfile::where('tenant_id', $tenant->id)->first();
        if ($existing?->hasActiveDirectDebit()) {
            return back()->withErrors(['billing' => __('Es besteht bereits ein aktives Lastschriftmandat.')]);
        }

        $sessionToken = Str::random(40);
        $request->session()->put(self::SESSION_KEY, $sessionToken);

        try {
            $flow = $this->gocardless->createRedirectFlow(
                $sessionToken,
                route('admin.billing.directdebit.complete'),
                __('Swayy-Abo: :plan', ['plan' => $plan->name]),
                $this->prefill($tenant, $request),
            );
        } catch (\Throwable $e) {
            Log::warning('GoCardless redirect flow failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['billing' => __('Die Lastschrift-Einrichtung konnte nicht gestartet werden. Bitte später erneut versuchen.')]);
        }

        return redirect()->away($flow['redirect_url']);
    }

    /**
     * GoCardless redirect target after the customer authorised the mandate.
     */
    public function complete(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $flowId = (string) $request->query('redirect_flow_id', '');
        $sessionToken = (string) $request->session()->pull(self::SESSION_KEY, '');

        if ($flowId === '' || $sessionToken === '') {
            return redirect()->route('admin.billing.show')
                ->withErrors(['billing' => __('Die Lastschrift-Einrichtung ist abgelaufen. Bitte erneut starten.')]);
        }

        $plan = $tenant->plan;
        if ($plan === null || (int) $plan->price_monthly_minor <= 0) {
            return redirect()->route('admin.billing.show')
                ->withErrors(['billing' => __('Für diesen Tarif ist keine Lastschrift möglich.')]);
        }

        try {
            $result = $this->gocardless->completeRedirectFlow($flowId, $sessionToken);
        } catch (\Throwable $e) {
            Log::warning('GoCardless complete failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return redirect()->route('admin.billing.show')
                ->withErrors(['billing' => __('Das Mandat konnte nicht abgeschlossen werden. Bitte erneut versuchen.')]);
        }

        if ($result['mandate_id'] === '') {
            return redirect()->route('admin.billing.show')
                ->withErrors(['billing' => __('Kein gültiges Mandat erhalten.')]);
        }

        // Claim-once: lock the billing profile and bail if a mandate is already
        // active, so a double return from GoCardless can't create two subscriptions.
        $subscriptionId = DB::transaction(function () use ($tenant, $plan, $result) {
            $profile = BillingProfile::where('tenant_id', $tenant->id)->lockForUpdate()->first();
            if ($profile === null) {
                $profile = new BillingProfile(['tenant_id' => $tenant->id]);
            }

            if ($profile->hasActiveDirectDebit()) {
                return null; // already set up
            }

            $subscriptionId = $this->gocardless->createSubscription(
                $result['mandate_id'],
                (int) $plan->price_monthly_minor,
                $plan->currency ?? 'EUR',
                'Swayy '.$tenant->name,
            );

            $profile->fill([
                'gocardless_customer_id' => $result['customer_id'],
                'gocardless_mandate_id' => $result['mandate_id'],
                'gocardless_subscription_id' => $subscriptionId,
                'gocardless_status' => 'active',
                'payment_status' => 'active',
            ])->save();

            // An active paying customer is no longer trialing/locked.
            $tenant->update(['status' => 'active', 'trial_ends_at' => null]);

            return $subscriptionId;
        });

        if ($subscriptionId === null) {
            return redirect()->route('admin.billing.show')
                ->with('success', __('Lastschrift ist bereits aktiv.'));
        }

        $this->audit->log('billing.directdebit.activated', $tenant, null, [
            'subscription_id' => $subscriptionId,
            'amount_minor' => $plan->price_monthly_minor,
        ], null, $request->user(), $tenant->id);

        $this->notifyBoth(
            $tenant,
            $request,
            __('Lastschrift aktiviert – :tenant', ['tenant' => $tenant->name]),
            __("Hallo,\n\ndas SEPA-Lastschriftmandat für „:tenant\" wurde eingerichtet.\nTarif: :plan (:amount/Monat).\nDie erste Abbuchung erfolgt zum nächsten Abrechnungstermin.\n\nDu kannst die Lastschrift jederzeit in den Abrechnungs-Einstellungen kündigen.", [
                'tenant' => $tenant->name,
                'plan' => $plan->name,
                'amount' => $this->money($plan->price_monthly_minor, $plan->currency ?? 'EUR'),
            ]),
        );

        return redirect()->route('admin.billing.show')
            ->with('success', __('Lastschrift erfolgreich eingerichtet.'));
    }

    /**
     * Cancel the subscription (and mandate) directly from the account.
     */
    public function cancel(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $profile = BillingProfile::where('tenant_id', $tenant->id)->first();
        if ($profile === null || ! $profile->hasActiveDirectDebit()) {
            return back()->withErrors(['billing' => __('Es besteht kein aktives Lastschriftmandat.')]);
        }

        try {
            $this->gocardless->cancelSubscription($profile->gocardless_subscription_id);
            if ($profile->gocardless_mandate_id) {
                $this->gocardless->cancelMandate($profile->gocardless_mandate_id);
            }
        } catch (\Throwable $e) {
            Log::warning('GoCardless cancel failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['billing' => __('Die Kündigung konnte nicht verarbeitet werden. Bitte später erneut versuchen.')]);
        }

        $profile->update([
            'gocardless_status' => 'cancelled',
            'gocardless_subscription_id' => null,
            'payment_status' => 'cancelled',
        ]);

        $this->audit->log('billing.directdebit.cancelled', $tenant, null, null, null, $request->user(), $tenant->id);

        $this->notifyBoth(
            $tenant,
            $request,
            __('Lastschrift gekündigt – :tenant', ['tenant' => $tenant->name]),
            __("Hallo,\n\ndas SEPA-Lastschriftmandat für „:tenant\" wurde gekündigt. Es erfolgen keine weiteren Abbuchungen.", [
                'tenant' => $tenant->name,
            ]),
        );

        return back()->with('success', __('Lastschrift gekündigt.'));
    }

    /**
     * Send the same notification to both the customer and the platform owner.
     */
    private function notifyBoth(Tenant $tenant, Request $request, string $subject, string $body): void
    {
        $customer = $tenant->billingProfile?->billing_email
            ?: $request->user()?->email;

        $owner = config('swayy.owner_email')
            ?: config('services.support_email')
            ?: config('mail.from.address');

        foreach (array_unique(array_filter([$customer, $owner])) as $to) {
            // Synchronous (sendNow) so the SEPA setup/cancellation confirmation
            // is guaranteed to go out, independent of the queue worker.
            Mail::to($to)->sendNow(new TemplatedMail($subject, $body));
        }
    }

    /**
     * @return array<string,string|null>
     */
    private function prefill(Tenant $tenant, Request $request): array
    {
        $profile = $tenant->billingProfile;
        $user = $request->user();
        $name = $user?->name ?? '';
        $space = strpos($name, ' ');

        return [
            'email' => $profile?->billing_email ?: $user?->email,
            'given_name' => $space !== false ? substr($name, 0, $space) : $name,
            'family_name' => $space !== false ? substr($name, $space + 1) : null,
            'company_name' => $profile?->company_name ?: $tenant->name,
            'address_line1' => $profile?->address_line1,
            'city' => $profile?->city,
            'postal_code' => $profile?->postal_code,
            'country_code' => $profile?->country ?: 'DE',
        ];
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, ',', '.').' '.strtoupper($currency);
    }
}

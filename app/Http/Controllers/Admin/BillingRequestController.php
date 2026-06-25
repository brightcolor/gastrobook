<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BillingRequestConfirmMail;
use App\Mail\BillingRequestOwnerMail;
use App\Models\BillingRequest;
use App\Models\Plan;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BillingRequestController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Trial-expired screen (shows form or pending state). */
    public function expired(): View
    {
        $tenant = $this->context->tenant() ?? abort(404);

        $pending = $tenant->latestBillingRequest()
            ->whereNotNull('confirmed_at')
            ->first();

        $plans = Plan::where('is_active', true)
            ->where('key', '!=', 'trial')
            ->orderBy('sort_order')
            ->get();

        return view('admin.trial.expired', compact('tenant', 'pending', 'plans'));
    }

    /** Store the billing request and send the confirmation e-mail. */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->context->tenant() ?? abort(404);

        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email:rfc', 'max:200'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'address_line1' => ['required', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:40'],
            'plan_key' => ['required', 'string', 'exists:plans,key'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $billingRequest = BillingRequest::create([
            ...$data,
            'tenant_id' => $tenant->id,
            'token' => Str::random(64),
        ]);

        Mail::to($billingRequest->contact_email)
            ->send(new BillingRequestConfirmMail($billingRequest));

        return redirect()->route('admin.trial.expired')
            ->with('success', 'Bitte prüfen Sie Ihr E-Mail-Postfach und klicken Sie den Bestätigungslink.');
    }

    /**
     * Confirm the customer's e-mail (public link, no auth needed).
     * After confirmation the tenant status switches to pending_billing
     * and the owner gets an e-mail.
     */
    public function confirm(string $token): View|RedirectResponse
    {
        $billingRequest = BillingRequest::where('token', $token)
            ->where('created_at', '>=', now()->subHours(72))
            ->firstOrFail();

        if ($billingRequest->confirmed_at) {
            return view('admin.trial.confirm-already');
        }

        $billingRequest->update(['confirmed_at' => now()]);

        // Unlock tenant to "pending_billing" so they see a "we got it" screen
        $billingRequest->tenant->update(['status' => 'pending_billing']);

        // Notify owner
        $ownerEmail = config('swayy.owner_email');
        if ($ownerEmail) {
            Mail::to($ownerEmail)->send(new BillingRequestOwnerMail($billingRequest));
            $billingRequest->update(['owner_notified_at' => now()]);
        }

        return view('admin.trial.confirmed', compact('billingRequest'));
    }

    /** List all billing requests (owner-only admin page). */
    public function index(): View
    {
        $requests = BillingRequest::with('tenant')
            ->latest()
            ->paginate(30);

        return view('admin.billing-requests.index', compact('requests'));
    }

    /**
     * Activate the tenant (owner clicks the link in the owner e-mail).
     * Sets status back to 'active' and pushes trial_ends_at to null.
     */
    public function activate(BillingRequest $billingRequest): RedirectResponse
    {
        $tenant = $billingRequest->tenant;

        // Switch to the requested plan
        $plan = Plan::where('key', $billingRequest->plan_key)->first();
        $tenant->update([
            'status' => 'active',
            'plan_id' => $plan?->id ?? $tenant->plan_id,
            'trial_ends_at' => null,
        ]);

        return redirect()->route('admin.billing-requests.index')
            ->with('success', "Konto \"{$tenant->name}\" wurde aktiviert (Tarif: {$billingRequest->plan_key}).");
    }
}

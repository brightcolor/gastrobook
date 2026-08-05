<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * One-click opt-out from the automatic guest campaigns.
 *
 * The link is a signed URL – no token table, no login, and it cannot be
 * tampered with to unsubscribe somebody else. Revoking consent is recorded in
 * the consent history, because withdrawal has to be as provable as the consent
 * itself (Art. 7 Abs. 3 DSGVO).
 */
class MarketingUnsubscribeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request, int $guest)
    {
        abort_unless($request->hasValidSignature(), 403);

        $profile = Guest::withoutGlobalScope('tenant')->find($guest);

        // A profile that is gone (deleted, anonymised) means there is nothing
        // left to unsubscribe – say so calmly instead of showing an error.
        if ($profile === null || $profile->anonymized) {
            return view('public.unsubscribed', ['name' => null, 'alreadyGone' => true]);
        }

        if ($profile->marketing_consent) {
            $profile->update(['marketing_consent' => false, 'marketing_consent_at' => now()]);

            GuestConsent::withoutGlobalScopes()->create([
                'tenant_id' => $profile->tenant_id,
                'guest_id' => $profile->id,
                'type' => 'marketing',
                'granted' => false,
                'channel' => 'email_link',
                'recorded_at' => now(),
            ]);

            $this->audit->log('guest.marketing_unsubscribed', $profile, null, null, null, null, $profile->tenant_id);
        }

        return view('public.unsubscribed', [
            'name' => $profile->first_name ?: $profile->last_name,
            'alreadyGone' => false,
        ]);
    }
}

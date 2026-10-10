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

        [$name, $alreadyGone] = $this->unsubscribe($guest);

        return view('public.unsubscribed', ['name' => $name, 'alreadyGone' => $alreadyGone]);
    }

    /**
     * Abmeldung per Klick aus dem Mailprogramm (RFC 8058): POST auf denselben
     * signierten Link, ohne Bestätigungsseite.
     */
    public function oneClick(Request $request, int $guest)
    {
        abort_unless($request->hasValidSignature(), 403);

        $this->unsubscribe($guest);

        return response('Abgemeldet.', 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * @return array{0: ?string, 1: bool} Vorname für die Seite, Profil nicht mehr vorhanden
     */
    private function unsubscribe(int $guest): array
    {
        $profile = Guest::withoutGlobalScope('tenant')->find($guest);

        // A profile that is gone (deleted, anonymised) means there is nothing
        // left to unsubscribe – say so calmly instead of showing an error.
        if ($profile === null || $profile->anonymized) {
            return [null, true];
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

        return [$profile->first_name ?: $profile->last_name, false];
    }
}

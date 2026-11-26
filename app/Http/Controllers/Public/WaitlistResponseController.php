<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Support\MailNotice;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WaitlistResponseController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function show(int $entry, string $token)
    {
        $waitlistEntry = $this->find($entry, $token);
        $offer = $waitlistEntry->offers()->withoutGlobalScopes()
            ->where('status', 'open')
            ->latest()
            ->first();

        return view('public.waitlist-offer', [
            'entry' => $waitlistEntry,
            'offer' => $offer,
            'location' => $waitlistEntry->location()->withoutGlobalScope('tenant')->first(),
        ]);
    }

    public function respond(Request $request, int $entry, string $token)
    {
        $waitlistEntry = $this->find($entry, $token);
        $offer = $waitlistEntry->offers()->withoutGlobalScopes()
            ->where('status', 'open')
            ->latest()
            ->firstOrFail();

        if ($request->input('decision') === 'accept') {
            try {
                $reservation = $this->waitlist->acceptOffer($offer);
            } catch (ValidationException $e) {
                // Ohne diesen Zweig lud die Seite unveraendert neu: derselbe
                // Knopf, keine Meldung, kein Tisch. Der Gast erfuhr nie, ob er
                // ihn bekommen hat, und klickte weiter.
                $du = $waitlistEntry->location()->withoutGlobalScope('tenant')->first()?->effectiveSettings()->du() ?? false;

                return back()->withErrors(['offer' => MailNotice::withSpamHint(__(
                    'Der Tisch ist leider gerade vergeben worden. Wir melden uns per E-Mail, sobald wieder etwas frei wird.'
                ), $du)]);
            }

            return redirect()->route('booking.confirmation', [
                'code' => $reservation->code,
                'token' => $reservation->manage_token,
            ]);
        }

        $this->waitlist->declineOffer($offer);

        return view('public.waitlist-declined', [
            'location' => $waitlistEntry->location()->withoutGlobalScope('tenant')->first(),
        ]);
    }

    private function find(int $entryId, string $token): WaitlistEntry
    {
        $entry = WaitlistEntry::withoutGlobalScopes()->findOrFail($entryId);

        if (! hash_equals($entry->manage_token, $token)) {
            abort(404);
        }

        return $entry;
    }
}

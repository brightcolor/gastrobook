<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Http\Request;

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
            $reservation = $this->waitlist->acceptOffer($offer);

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

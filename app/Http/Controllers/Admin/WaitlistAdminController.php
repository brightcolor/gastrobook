<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class WaitlistAdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WaitlistService $waitlist,
    ) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $date = $request->input('date', CarbonImmutable::now($location->timezone)->toDateString());

        $entries = WaitlistEntry::query()
            ->where('location_id', $location->id)
            ->whereDate('desired_date', $date)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->with('offers')
            ->get();

        return view('admin.waitlist.index', compact('location', 'entries', 'date'));
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->waitlist->createEntry($location, [
            'guest_name' => $validated['name'],
            'guest_email' => $validated['email'] ?? null,
            'guest_phone' => $validated['phone'] ?? null,
            'party_size' => (int) $validated['party_size'],
            'desired_date' => $validated['date'],
            'desired_time' => $validated['time'] ?? null,
            'note' => $validated['note'] ?? null,
            'source' => 'staff',
        ], $request->user());

        return back()->with('success', __('Wartelisteneintrag angelegt.'));
    }

    public function offer(Request $request, WaitlistEntry $entry)
    {
        $location = $this->context->location();
        abort_if($entry->location_id !== $location?->id, 404);

        $validated = $request->validate([
            'time' => ['required', 'date_format:H:i'],
            'valid_minutes' => ['nullable', 'integer', 'min:10', 'max:480'],
        ]);

        $startLocal = CarbonImmutable::parse($entry->desired_date->toDateString().' '.$validated['time'], $location->timezone);
        $duration = $location->effectiveSettings()->durationFor($entry->party_size);

        $this->waitlist->offer(
            $entry,
            $startLocal->utc(),
            $startLocal->utc()->addMinutes($duration),
            $request->user(),
            (int) ($validated['valid_minutes'] ?? 60)
        );

        return back()->with('success', __('Angebot versendet.'));
    }

    public function seat(Request $request, WaitlistEntry $entry)
    {
        $location = $this->context->location();
        abort_if($entry->location_id !== $location?->id, 404);

        $offer = $entry->offers()->where('status', 'open')->latest()->first();
        if ($offer === null) {
            $duration = $location->effectiveSettings()->durationFor($entry->party_size);
            $offer = $this->waitlist->offer($entry, now()->toImmutable(), now()->toImmutable()->addMinutes($duration), $request->user(), 15);
        }

        $reservation = $this->waitlist->acceptOffer($offer);

        return redirect()->route('admin.reservations.show', $reservation)
            ->with('success', __('Gast von der Warteliste übernommen.'));
    }

    public function cancel(WaitlistEntry $entry)
    {
        abort_if($entry->location_id !== $this->context->location()?->id, 404);
        $entry->update(['status' => 'cancelled']);

        return back()->with('success', __('Eintrag entfernt.'));
    }
}

<?php

namespace App\Services;

use App\Mail\TemplatedMail;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class WaitlistService
{
    public function __construct(
        private readonly ReservationLifecycleService $lifecycle,
        private readonly WebhookDispatchService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{
     *     guest_name: string, guest_email?: ?string, guest_phone?: ?string,
     *     party_size: int, desired_date: string, desired_time?: ?string,
     *     flex_minutes?: int, source?: string, note?: ?string, priority?: int,
     * } $data
     */
    public function createEntry(Location $location, array $data, ?User $actor = null): WaitlistEntry
    {
        if (! $location->effectiveSettings()->waitlist_enabled) {
            throw ValidationException::withMessages(['waitlist' => __('Die Warteliste ist für diesen Standort deaktiviert.')]);
        }

        $desiredStartUtc = null;
        if (! empty($data['desired_time'])) {
            $desiredStartUtc = CarbonImmutable::parse(
                $data['desired_date'].' '.$data['desired_time'],
                $location->timezone
            )->utc();
        }

        $entry = WaitlistEntry::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'guest_name' => $data['guest_name'],
            'guest_email' => $data['guest_email'] ?? null,
            'guest_phone' => $data['guest_phone'] ?? null,
            'party_size' => $data['party_size'],
            'desired_date' => $data['desired_date'],
            'desired_start_at' => $desiredStartUtc,
            'flex_minutes' => $data['flex_minutes'] ?? 60,
            'status' => 'waiting',
            'source' => $data['source'] ?? 'online',
            'priority' => $data['priority'] ?? 100,
            'note' => $data['note'] ?? null,
            'expires_at' => CarbonImmutable::parse($data['desired_date'], $location->timezone)->endOfDay()->utc(),
        ]);

        $this->audit->log('waitlist.created', $entry, null, ['party_size' => $entry->party_size], null, $actor, $location->tenant_id);
        $this->webhooks->dispatch($location->tenant, 'waitlist.created', [
            'id' => $entry->id, 'party_size' => $entry->party_size, 'desired_date' => $data['desired_date'],
        ]);

        return $entry;
    }

    /**
     * Offer a free slot to a waiting guest (mail with accept link).
     */
    public function offer(WaitlistEntry $entry, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?User $actor = null, int $validMinutes = 60): WaitlistOffer
    {
        $offer = WaitlistOffer::create([
            'tenant_id' => $entry->tenant_id,
            'waitlist_entry_id' => $entry->id,
            'offered_start_at' => $startUtc,
            'offered_end_at' => $endUtc,
            'offer_expires_at' => now()->addMinutes($validMinutes),
            'status' => 'open',
            'created_by' => $actor?->id,
        ]);

        $entry->update(['status' => 'offered']);

        $location = $entry->location()->withoutGlobalScope('tenant')->first();
        if ($entry->guest_email && $location) {
            $link = route('waitlist.respond', ['entry' => $entry->id, 'token' => $entry->manage_token]);
            $startLocal = $startUtc->setTimezone($location->timezone);
            $du = $location->effectiveSettings()->du();
            $vars = [
                'name' => $entry->guest_name,
                'date' => $startLocal->format('d.m.Y'),
                'time' => $startLocal->format('H:i'),
                'party' => $entry->party_size,
                'minutes' => $validMinutes,
                'link' => $link,
                'location' => $location->name,
            ];
            Mail::to($entry->guest_email)->queue(new TemplatedMail(
                __('Ein Tisch ist frei geworden – :location', ['location' => $location->name]),
                $du
                    ? __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden.\n\nBitte bestätige innerhalb von :minutes Minuten:\n:link\n\n:location", $vars)
                    : __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden.\n\nBitte bestätigen Sie innerhalb von :minutes Minuten:\n:link\n\n:location", $vars),
            ));
        }

        $this->audit->log('waitlist.offered', $entry, null, ['offer_id' => $offer->id], null, $actor, $entry->tenant_id);
        if ($location) {
            $this->webhooks->dispatch($location->tenant, 'waitlist.offered', ['entry_id' => $entry->id, 'offer_id' => $offer->id]);
        }

        return $offer;
    }

    /**
     * Guest accepts an open offer → creates a confirmed reservation.
     */
    public function acceptOffer(WaitlistOffer $offer): Reservation
    {
        // Fast pre-check for the common case; the authoritative check happens
        // under a row lock below.
        if ($offer->status !== 'open' || $offer->offer_expires_at->isPast()) {
            throw ValidationException::withMessages(['offer' => __('Dieses Angebot ist nicht mehr gültig.')]);
        }

        return DB::transaction(function () use ($offer) {
            // Lock the offer row and re-check inside the transaction. Without this
            // two concurrent accepts (guest double-clicks the link) could both pass
            // the pre-check and each create a reservation from a single offer
            // (= double booking, two tables consumed).
            $offer = WaitlistOffer::withoutGlobalScopes()->lockForUpdate()->find($offer->id);
            if ($offer === null || $offer->status !== 'open' || $offer->offer_expires_at->isPast()) {
                throw ValidationException::withMessages(['offer' => __('Dieses Angebot ist nicht mehr gültig.')]);
            }

            $entry = $offer->entry()->withoutGlobalScope('tenant')->first();
            $location = $entry->location()->withoutGlobalScope('tenant')->first();

            $reservation = $this->lifecycle->create($location, [
                'party_size' => $entry->party_size,
                'start_local' => CarbonImmutable::parse($offer->offered_start_at)->setTimezone($location->timezone),
                'source' => 'online',
                'guest_name' => $entry->guest_name,
                'guest_email' => $entry->guest_email,
                'guest_phone' => $entry->guest_phone,
                'guest_note' => $entry->note,
            ]);

            $offer->update(['status' => 'accepted']);
            $entry->update(['status' => 'accepted', 'reservation_id' => $reservation->id]);

            $this->webhooks->dispatch($location->tenant, 'waitlist.accepted', [
                'entry_id' => $entry->id, 'reservation_code' => $reservation->code,
            ]);

            return $reservation;
        });
    }

    public function declineOffer(WaitlistOffer $offer): void
    {
        $offer->update(['status' => 'declined']);
        $offer->entry()->withoutGlobalScope('tenant')->first()?->update(['status' => 'waiting']);
    }

    /**
     * Scheduler: expire stale offers and outdated entries.
     */
    public function expireStale(): int
    {
        $expiredOffers = WaitlistOffer::withoutGlobalScopes()
            ->where('status', 'open')
            ->where('offer_expires_at', '<', now())
            ->get();

        foreach ($expiredOffers as $offer) {
            $offer->update(['status' => 'expired']);
            $offer->entry()->withoutGlobalScopes()->first()?->update(['status' => 'waiting']);
        }

        $expiredEntries = WaitlistEntry::withoutGlobalScopes()
            ->whereIn('status', ['waiting', 'offered'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        return $expiredOffers->count() + $expiredEntries;
    }
}

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
        private readonly ReservationAvailabilityService $availability,
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
     *
     * @param  bool  $sofort  Der Gast steht bereits da und wird vom Personal
     *                        platziert. Dann wird nicht gegen andere Angebote
     *                        gerechnet - der Tisch ist frei, sonst stuende
     *                        niemand davor.
     */
    public function offer(WaitlistEntry $entry, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?User $actor = null, int $validMinutes = 60, bool $sofort = false): WaitlistOffer
    {
        // Die TRANSAKTION traegt den Schutz: Wirft die Pruefung, rollt sie das
        // Schliessen der alten Angebote mit zurueck. Ohne sie stuende der Gast
        // nach einem abgelehnten Anlauf ganz ohne Angebot da - der Link in
        // seiner Mail waere tot, und niemand erfuehre davon. Die Reihenfolge
        // (erst pruefen, dann schliessen) ist der zweite Boden darunter.
        return DB::transaction(function () use ($entry, $startUtc, $endUtc, $actor, $validMinutes, $sofort) {
            $tische = $sofort ? [] : $this->assertCapacityLeft($entry, $startUtc, $endUtc);

            WaitlistOffer::withoutGlobalScopes()
                ->where('waitlist_entry_id', $entry->id)
                ->where('status', 'open')
                ->update(['status' => 'superseded']);

            return $this->createOffer($entry, $startUtc, $endUtc, $actor, $validMinutes, $tische);
        });
    }

    /**
     * Ist zu diesem Fenster ueberhaupt noch Platz - fuer DIESEN Gast?
     *
     * Gemessen an der Kapazitaet, nicht an der blossen Zahl offener Angebote:
     * Ein Betrieb mit vierzig Tischen darf denselben Abend mehreren Wartenden
     * anbieten. Was andere offene Angebote versprochen haben, gilt dabei als
     * belegt.
     *
     * Entscheidend ist, WIE es als belegt gilt. Die Plaetze der anderen auf die
     * eigene Gruppe zu addieren und nach EINEM Platz fuer die Summe zu fragen,
     * bildet die Wirklichkeit nicht ab: Zwei Vierergruppen brauchen keinen
     * Achtertisch - und zwei Zweiergruppen passen nicht deshalb beide an einen
     * Zehnertisch, weil vier kleiner ist als zehn. Die erste Rechnung wies
     * Gaeste ab, obwohl ein Tisch leer stand; die zweite versprach zweien
     * denselben Tisch, und wer zweiter klickte, bekam eine Fehlermeldung.
     *
     * Deshalb: Die zugesagten TISCHE gelten als belegt, gefragt wird nach einem
     * Platz fuer die eigene Gruppe. Nur wo es gar keine Tische gibt (reine
     * Personenzaehlung), sind Plaetze additiv - dort zaehlen sie als
     * `extra_covers` mit.
     *
     * Die Pruefung ist beratend, nicht bindend: Sie haelt keine Sperre, zwei
     * gleichzeitige Angebote koennen also beide durchkommen. Der verbindliche
     * Halt sitzt in acceptOffer, das ueber lifecycle->create unter der
     * Slot-Sperre nochmal vollstaendig prueft. Schlimmstenfalls entsteht hier
     * eine Zusage zu viel - kein doppelt belegter Tisch.
     *
     * @return array<int, int> Die Tische, die dieses Angebot verspricht.
     */
    private function assertCapacityLeft(WaitlistEntry $entry, CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        $location = $entry->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return [];
        }

        $offene = WaitlistOffer::withoutGlobalScopes()
            ->join('waitlist_entries', 'waitlist_entries.id', '=', 'waitlist_offers.waitlist_entry_id')
            ->where('waitlist_offers.status', 'open')
            ->where('waitlist_offers.offer_expires_at', '>', now())
            ->where('waitlist_offers.waitlist_entry_id', '!=', $entry->id)
            ->where('waitlist_entries.location_id', $location->id)
            ->where('waitlist_offers.offered_start_at', '<', $endUtc)
            ->where('waitlist_offers.offered_end_at', '>', $startUtc)
            ->get(['waitlist_offers.table_ids', 'waitlist_entries.party_size as entry_party_size']);

        $belegt = $offene->flatMap(fn (WaitlistOffer $o) => $o->table_ids ?? [])->unique()->values()->all();
        $vergeben = (int) $offene->sum('entry_party_size');

        // Ein Angebot ohne festgehaltene Tische sperrt keinen - bei reiner
        // Personenzaehlung gibt es keine, "Gast steht schon da" haelt keine
        // frei, und Angebote von vor dieser Aenderung tragen sie nicht.
        //
        // In der Tischsuche zaehlen ihre Plaetze darum wieder auf die eigene
        // Gruppe. Das ist die alte, grobe Rechnung - aber sie irrt nach oben:
        // lieber eine Zusage zu wenig als zwei auf denselben Tisch. Ohne diese
        // Zeile faellt so ein Angebot durch beide Raster, und beim Ausrollen
        // stuende der behobene Fehler acht Stunden lang wieder offen.
        $ohneTische = (int) $offene
            ->filter(fn (WaitlistOffer $o) => empty($o->table_ids))
            ->sum('entry_party_size');

        $startLocal = CarbonImmutable::parse($startUtc)->setTimezone($location->timezone);
        $optionen = [
            // Die Dauer stammt vom aufrufenden Fenster, nicht aus der
            // Gruppengroesse - der Aufrufer hat sie bereits gerechnet.
            'duration' => (int) $startUtc->diffInMinutes($endUtc),
            'online' => false,
            'ad_hoc' => true,
            'busy_table_ids' => $belegt,
            // Nur die Plaetze der Angebote MIT Tischen: Die anderen stecken
            // schon in der Gruppengroesse und zaehlten sonst doppelt.
            'extra_covers' => $vergeben - $ohneTische,
        ];

        $check = $this->availability->checkExact($location, $startLocal, $entry->party_size + $ohneTische, $optionen);

        if (! $check['available']) {
            throw ValidationException::withMessages([
                'time' => __('Für diesen Zeitraum ist gerade nichts mehr frei – bitte zuerst einen Tisch freigeben.'),
            ]);
        }

        // Versprochen wird, was DIESE Gruppe braucht. Die Aufschlaege oben
        // sind nur der Riegel; als Zusage festgehalten waeren sie zu viel.
        if ($ohneTische === 0) {
            return $check['table_ids'];
        }

        return $this->availability->checkExact($location, $startLocal, $entry->party_size, $optionen)['table_ids'];
    }

    /**
     * @param  array<int, int>  $tableIds
     */
    private function createOffer(WaitlistEntry $entry, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?User $actor, int $validMinutes, array $tableIds = []): WaitlistOffer
    {
        $offer = WaitlistOffer::create([
            'tenant_id' => $entry->tenant_id,
            'waitlist_entry_id' => $entry->id,
            'offered_start_at' => $startUtc,
            'offered_end_at' => $endUtc,
            // Festhalten, was versprochen wurde: Das naechste Angebot rechnet
            // diese Tische als belegt und verspricht sie nicht ein zweites Mal.
            'table_ids' => $tableIds ?: null,
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
            // afterCommit, weil das Anlegen des Angebots in einer Transaktion
            // laeuft: Die Queue liegt auf Redis, nicht in derselben Datenbank.
            // Ein Arbeiter koennte die Mail also verschicken, bevor - oder
            // ohne dass - das Angebot ueberhaupt existiert.
            Mail::to($entry->guest_email)->queue((new TemplatedMail(
                __('Ein Tisch ist frei geworden – :location', ['location' => $location->name]),
                $du
                    ? __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden.\n\nBitte bestätige innerhalb von :minutes Minuten:\n:link\n\n:location", $vars)
                    : __("Hallo :name,\n\nfür :date um :time Uhr ist ein Tisch für :party Personen frei geworden.\n\nBitte bestätigen Sie innerhalb von :minutes Minuten:\n:link\n\n:location", $vars),
            ))->afterCommit());
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
                // Die Zeit stammt aus dem Angebot des Betriebs, nicht aus dem
                // oeffentlichen Raster. "Jetzt platzieren" faellt sonst an der
                // Rasterpruefung und an der Vorlaufzeit durch.
                'ad_hoc' => true,
                // Und die Tische aus dem Angebot einloesen, statt neu zu
                // suchen. Die Suche lief hier mit 'online', das Angebot ohne:
                // Ein Tisch, der nicht online buchbar ist, wurde zugesagt und
                // war beim Annehmen dann "nicht mehr frei". Der Gast bekam eine
                // Fehlermeldung fuer einen Tisch, der die ganze Zeit dastand.
                'table_ids' => $offer->table_ids ?? [],
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
        $this->backToWaiting((int) $offer->waitlist_entry_id);
    }

    /**
     * Den Eintrag zurueck auf "wartet" - aber nur, wenn er noch auf ein
     * Angebot wartet.
     *
     * Vorher lief das bedingungslos. Ein bereits angenommener Eintrag wurde
     * damit vom Aufraeumlauf wieder auf "wartet" gesetzt - mit hinterlegter
     * Reservierung. Wird er ein zweites Mal bedient, blockiert derselbe Gast
     * zwei Tische.
     */
    private function backToWaiting(int $entryId): void
    {
        WaitlistEntry::withoutGlobalScopes()
            ->whereKey($entryId)
            ->where('status', 'offered')
            // Nur wenn kein anderes Angebot mehr offen ist - sonst reisst das
            // Aufraeumen des alten Angebots das laufende mit.
            ->whereNotExists(function ($q) use ($entryId) {
                $q->selectRaw('1')
                    ->from('waitlist_offers')
                    ->where('waitlist_entry_id', $entryId)
                    ->where('status', 'open');
            })
            ->update(['status' => 'waiting']);
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
            $this->backToWaiting((int) $offer->waitlist_entry_id);
        }

        $expiredEntries = WaitlistEntry::withoutGlobalScopes()
            ->whereIn('status', ['waiting', 'offered'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        return $expiredOffers->count() + $expiredEntries;
    }
}

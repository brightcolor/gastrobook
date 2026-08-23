<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\Guest;
use App\Models\Location;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Automatic guest campaigns.
 *
 * Three rules, deliberately narrow and all consent-gated:
 *   birthday  – X days before the birthday, once a year
 *   rebooking – exactly X days after the last visit, once per visit
 *   winback   – no visit for X days, at most once a year
 *
 * Nothing goes out without `marketing_consent`, and every mail carries a
 * one-click opt-out. A guest who never visited this location is never mailed
 * for it – otherwise a second location would double-send to the same person.
 */
class MarketingCampaignService
{
    /** Safety net: a single run never mails more than this per campaign. */
    public const MAX_PER_RUN = 500;

    /**
     * Wie viele Tage ein Lauf nachholt.
     *
     * Geburtstage und Wiederbuchungen arbeiteten auf einem Fenster von genau
     * einem Tag. Lief der Job an einem Tag nicht - Worker gestorben, Deploy
     * ueber Nacht -, betrachtete der Lauf am Folgetag ein anderes Fenster: Die
     * uebersprungene Gruppe fiel dauerhaft heraus und wurde nie angeschrieben.
     * Der Dedupe-Schluessel haengt am Besuchstag bzw. am Jahr, nicht am
     * Ausfuehrungstag - ein Nachholen schreibt darum niemandem zweimal.
     */
    public const CATCH_UP_DAYS = 3;

    /**
     * Everyone this campaign would mail today.
     *
     * @return Collection<int, Guest>
     */
    public function recipients(MarketingCampaign $campaign, ?CarbonImmutable $today = null): Collection
    {
        $location = $campaign->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return new Collection;
        }

        $today ??= CarbonImmutable::now($location->timezone)->startOfDay();

        $query = Guest::withoutGlobalScope('tenant')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('anonymized', false)
            ->where('marketing_consent', true)
            ->whereNotNull('email')
            ->where('visit_count', '>=', max(0, (int) $campaign->min_visits))
            // Nur Gaeste, die hier wirklich WAREN. Ohne den Statusfilter zaehlt
            // auch eine stornierte oder noch bevorstehende Buchung als Besuch –
            // dann bekommt jemand "schoen war's bei uns" von einem Betrieb, in
            // dem er nie gegessen hat.
            ->whereHas('reservations', fn ($q) => $q->where('location_id', $location->id)
                ->whereIn('status', [
                    ReservationStatus::Completed->value,
                    ReservationStatus::Seated->value,
                    ReservationStatus::PartiallyArrived->value,
                ]));

        // Visit timestamps are stored in UTC, the campaign thinks in local
        // days: comparing the two as dates would be off by hours at the edges,
        // so the local day is translated into a UTC window instead.
        $visitDay = $today->subDays((int) $campaign->offset_days);

        $query = match ($campaign->type) {
            'birthday' => $this->birthdayFilter($query, $today->addDays((int) $campaign->offset_days)),
            'rebooking' => $query->where('last_visit_at', '>=', $visitDay->subDays(self::CATCH_UP_DAYS)->utc())
                ->where('last_visit_at', '<', $visitDay->addDay()->utc()),
            'winback' => $query->whereNotNull('last_visit_at')
                ->where('last_visit_at', '<', $visitDay->addDay()->utc()),
            default => $query->whereRaw('1 = 0'),
        };

        // Alle Schluessel des Nachholfensters, nicht nur der von heute.
        $alreadySent = MarketingSend::withoutGlobalScopes()
            ->where('marketing_campaign_id', $campaign->id)
            ->whereIn('reference', $this->referenceWindow($campaign, $today))
            ->pluck('guest_id')
            ->all();

        // Win-back looks back a whole year, not just at today's reference:
        // someone written to in March must not be written to again in April.
        if ($campaign->type === 'winback') {
            $alreadySent = MarketingSend::withoutGlobalScopes()
                ->where('marketing_campaign_id', $campaign->id)
                ->where('sent_at', '>=', $today->subYear())
                ->pluck('guest_id')
                ->all();
        }

        return $query->whereNotIn('id', $alreadySent)
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get();
    }

    /**
     * Send today's mails for one campaign. Returns how many went out.
     */
    public function run(MarketingCampaign $campaign, ?CarbonImmutable $today = null): int
    {
        $location = $campaign->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return 0;
        }

        $today ??= CarbonImmutable::now($location->timezone)->startOfDay();
        $sent = 0;

        foreach ($this->recipients($campaign, $today) as $guest) {
            // Der Schluessel haengt am Anlass des GASTES, nicht am Tag des
            // Laufs - sonst schriebe ein nachholender Lauf jemanden ein
            // zweites Mal an.
            $reference = $this->referenceFor($campaign, $today, $guest, $location);

            // Claim the slot first: a crash halfway through must not turn into
            // a second mail on the retry.
            $claim = MarketingSend::withoutGlobalScopes()->firstOrCreate(
                [
                    'marketing_campaign_id' => $campaign->id,
                    'guest_id' => $guest->id,
                    'reference' => $reference,
                ],
                [
                    'tenant_id' => $campaign->tenant_id,
                    'sent_at' => now(),
                ]
            );
            if (! $claim->wasRecentlyCreated) {
                continue;
            }

            $this->deliver($campaign, $location, $guest);
            $sent++;
        }

        $campaign->forceFill(['last_run_at' => now()])->save();

        return $sent;
    }

    /**
     * Render subject and body for a guest – also used for the preview and the
     * test mail in the admin.
     *
     * @return array{subject: string, body: string}
     */
    public function render(MarketingCampaign $campaign, Location $location, Guest $guest): array
    {
        $tenant = $location->tenant;
        $bookingUrl = $location->tenant->locations()->where('is_active', true)->count() <= 1
            ? route('booking.landing', $tenant->slug)
            : route('booking.show', [$tenant->slug, $location->slug]);

        $replace = [
            '{guest_name}' => trim(($guest->first_name ?? '').' '.$guest->last_name),
            '{first_name}' => $guest->first_name ?: $guest->last_name,
            '{location_name}' => $location->name,
            '{business_name}' => $tenant->name,
            '{booking_link}' => $bookingUrl,
            '{unsubscribe_link}' => $this->unsubscribeUrl($guest),
        ];

        $body = strtr($campaign->body, $replace);

        // The opt-out is not optional – append it if the text forgot it.
        if (! str_contains($campaign->body, '{unsubscribe_link}')) {
            $body .= "\n\n---\nKeine Werbung mehr von uns? Hier abmelden: ".$replace['{unsubscribe_link}'];
        }

        return [
            'subject' => strtr($campaign->subject, $replace),
            'body' => $body,
        ];
    }

    public function unsubscribeUrl(Guest $guest): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['guest' => $guest->id]);
    }

    private function deliver(MarketingCampaign $campaign, Location $location, Guest $guest): void
    {
        $rendered = $this->render($campaign, $location, $guest);
        $tenant = $location->tenant;

        Mail::to($guest->email)->queue(new TemplatedMail(
            $rendered['subject'],
            $rendered['body'],
            $tenant->mail_from_name,
            $tenant->mail_reply_to,
        ));

        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $campaign->tenant_id,
            'location_id' => $location->id,
            'channel' => 'mail',
            'template_key' => 'marketing.'.$campaign->type,
            'recipient' => $guest->email,
            'subject' => $rendered['subject'],
            'status' => 'queued',
        ]);
    }

    /**
     * Occasion key for the dedupe row.
     */
    private function reference(MarketingCampaign $campaign, CarbonImmutable $today): string
    {
        return match ($campaign->type) {
            // Birthday: once per calendar year. Rebooking: once per visit day.
            // Win-back: once per year, plus the 12-month look-back in recipients().
            'birthday' => 'b-'.$today->addDays((int) $campaign->offset_days)->year,
            'rebooking' => 'v-'.$today->subDays((int) $campaign->offset_days)->toDateString(),
            default => 'w-'.$today->year,
        };
    }

    /**
     * Der Schluessel fuer DIESEN Gast.
     *
     * Bei Wiederbuchungen ist der Anlass sein letzter Besuch, nicht der Tag,
     * an dem der Lauf zufaellig stattfindet. Nur so holt ein spaeterer Lauf
     * eine uebersprungene Gruppe nach, ohne jemandem zweimal zu schreiben.
     */
    private function referenceFor(MarketingCampaign $campaign, CarbonImmutable $today, Guest $guest, Location $location): string
    {
        if ($campaign->type === 'rebooking' && $guest->last_visit_at !== null) {
            return 'v-'.$guest->last_visit_at->copy()->setTimezone($location->timezone)->toDateString();
        }

        // Geburtstag: das Jahr des GETROFFENEN Anlasses, nicht das des Laufs.
        // Ueber den Jahreswechsel liegt der 31.12. im Nachholfenster des
        // 1. Januar - mit dem Lauftag als Schluessel bekaeme jeder Gast mit
        // Geburtstag am 29. bis 31.12. seine Mail ein zweites Mal.
        if ($campaign->type === 'birthday' && $guest->birthday !== null) {
            $ziel = $today->addDays((int) $campaign->offset_days);
            $jahr = (int) $ziel->format('m') === 1 && (int) $guest->birthday->format('m') === 12
                ? $ziel->year - 1
                : $ziel->year;

            return 'b-'.$jahr;
        }

        return $this->reference($campaign, $today);
    }

    /**
     * Alle Schluessel, die ein nachholender Lauf schon vergeben haben koennte.
     *
     * @return array<int, string>
     */
    private function referenceWindow(MarketingCampaign $campaign, CarbonImmutable $today): array
    {
        if ($campaign->type === 'birthday') {
            // Beide Jahre: Das Nachholfenster greift ueber den Jahreswechsel
            // zurueck, und der Schluessel eines nachgeholten Geburtstags traegt
            // das alte Jahr.
            $ziel = $today->addDays((int) $campaign->offset_days);

            return ['b-'.$ziel->year, 'b-'.($ziel->year - 1)];
        }

        if ($campaign->type !== 'rebooking') {
            return [$this->reference($campaign, $today)];
        }

        $schluessel = [];
        for ($i = 0; $i <= self::CATCH_UP_DAYS; $i++) {
            $schluessel[] = 'v-'.$today->subDays((int) $campaign->offset_days + $i)->toDateString();
        }

        return $schluessel;
    }

    /**
     * Birthday match on month and day – the year is irrelevant, and the date
     * extraction is left to the query builder so it works on SQLite as well as
     * on Postgres.
     *
     * @param  Builder<Guest>  $query
     * @return Builder<Guest>
     */
    private function birthdayFilter(Builder $query, CarbonImmutable $target): Builder
    {
        // Auch die Tage davor: Ein ausgefallener Lauf hat sonst die
        // Geburtstagskinder dieses Tages fuer immer uebersprungen. Der
        // Dedupe-Schluessel steht auf dem Kalenderjahr, ein Nachholen schreibt
        // also niemandem zweimal.
        return $query->whereNotNull('birthday')
            ->where(function (Builder $q) use ($target) {
                for ($i = 0; $i <= self::CATCH_UP_DAYS; $i++) {
                    $tag = $target->subDays($i);
                    $q->orWhere(fn (Builder $inner) => $inner
                        ->whereMonth('birthday', (int) $tag->format('m'))
                        ->whereDay('birthday', (int) $tag->format('d')));
                }
            });
    }
}

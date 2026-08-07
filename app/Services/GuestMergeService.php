<?php

namespace App\Services;

use App\Models\EventBooking;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\GuestConsent;
use App\Models\GuestMergeLog;
use App\Models\GuestNote;
use App\Models\MarketingSend;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merging duplicate guest profiles.
 *
 * Dedupe on creation (GuestProfileService) only catches what arrives through
 * the same channel: a guest who books once by mail and once by phone still ends
 * up twice. This folds the two together – history, notes, consents and counters
 * move over, the duplicate disappears, and the snapshot in `guest_merge_logs`
 * keeps the step traceable.
 */
class GuestMergeService
{
    /** Fields taken from the duplicate whenever the kept profile has nothing. */
    private const FILLABLE_BLANKS = [
        'first_name', 'last_name', 'email', 'phone',
        'address_line1', 'postal_code', 'city', 'country', 'birthday', 'locale',
        'preferred_location_id', 'preferred_room_id', 'preferred_table_id',
        'preferences', 'allergies', 'accessibility_notes', 'source',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Likely duplicates of a guest: same mail address, same phone number, or
     * the same name. Deliberately narrow – a wrong merge cannot be undone with
     * one click.
     *
     * @return Collection<int, Guest>
     */
    public function candidatesFor(Guest $guest, int $limit = 5): Collection
    {
        $email = $guest->email ? mb_strtolower($guest->email) : null;
        $phone = $guest->phone_normalized;
        $lastName = trim((string) $guest->last_name);

        return Guest::query()
            ->where('id', '!=', $guest->id)
            ->where('anonymized', false)
            ->where(function ($q) use ($email, $phone, $lastName, $guest) {
                $matched = false;
                if ($email !== null && $email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                    $matched = true;
                }
                if ($phone !== null && $phone !== '') {
                    $q->orWhere('phone_normalized', $phone);
                    $matched = true;
                }
                if ($lastName !== '') {
                    $q->orWhere(function ($inner) use ($lastName, $guest) {
                        $inner->where('last_name', $lastName)
                            ->where('first_name', $guest->first_name);
                    });
                    $matched = true;
                }
                if (! $matched) {
                    // Nothing to match on – return no candidates instead of all.
                    $q->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('visit_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Fold $duplicate into $keep. Everything that points at the duplicate is
     * repointed first, then the duplicate is removed for good.
     */
    public function merge(Guest $keep, Guest $duplicate, ?User $actor = null): Guest
    {
        if ($keep->id === $duplicate->id) {
            throw ValidationException::withMessages([
                'merge_guest_id' => __('Ein Gast kann nicht mit sich selbst zusammengeführt werden.'),
            ]);
        }
        if ($keep->tenant_id !== $duplicate->tenant_id) {
            throw ValidationException::withMessages([
                'merge_guest_id' => __('Die beiden Profile gehören zu verschiedenen Betrieben.'),
            ]);
        }
        if ($keep->anonymized || $duplicate->anonymized) {
            throw ValidationException::withMessages([
                'merge_guest_id' => __('Anonymisierte Profile lassen sich nicht zusammenführen.'),
            ]);
        }

        return DB::transaction(function () use ($keep, $duplicate, $actor) {
            $snapshot = $duplicate->attributesToArray();

            // Everything that hangs off the duplicate moves over. The tenant
            // scope is bypassed on purpose: the guest id already limits this to
            // one tenant, and jobs/console runs have no tenant context.
            $moved = [
                'reservations' => Reservation::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                'notes' => GuestNote::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                'consents' => GuestConsent::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                'waitlist_entries' => WaitlistEntry::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                'event_bookings' => EventBooking::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                'auth_tokens' => GuestAuthToken::withoutGlobalScopes()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]),
                // Die Versandsperre der Kampagnen haengt am Gast und wuerde beim
                // Loeschen mitgehen – dieselbe Person bekaeme dieselbe Mail ein
                // zweites Mal. Zeilen, die es beim behaltenen Profil fuer denselben
                // Anlass schon gibt, wuerden den Unique-Index verletzen: die
                // erfuellen ihren Zweck bereits und werden verworfen.
                'marketing_sends' => $this->moveMarketingSends($keep, $duplicate),
                // Snapshots frueherer Merges dieses Profils – sonst loescht die
                // Kaskade die einzige verbliebene Kopie des damaligen Duplikats.
                'merge_logs' => GuestMergeLog::withoutGlobalScopes()->where('kept_guest_id', $duplicate->id)->update(['kept_guest_id' => $keep->id]),
            ];

            $keep->tags()->syncWithoutDetaching($duplicate->tags()->pluck('tags.id')->all());

            $keep->fill($this->mergedAttributes($keep, $duplicate));
            $keep->save();

            $log = GuestMergeLog::create([
                'tenant_id' => $keep->tenant_id,
                'kept_guest_id' => $keep->id,
                'merged_guest_id' => $duplicate->id,
                'merged_snapshot' => $snapshot + ['moved' => $moved],
                'user_id' => $actor?->id,
            ]);

            // Hard delete: a soft-deleted twin would keep blocking the mail
            // address and show up as a ghost in exports. The snapshot above is
            // the record that survives.
            $duplicate->forceDelete();

            $this->audit->log('guest.merged', $keep, null, [
                'merged_guest_id' => $log->merged_guest_id,
                'moved' => $moved,
            ], null, $actor, $keep->tenant_id);

            return $keep->refresh();
        });
    }

    /**
     * Versandhistorie umhaengen, ohne den Unique-Index (Kampagne, Gast, Anlass)
     * zu verletzen: Was es beim behaltenen Profil fuer denselben Anlass schon
     * gibt, wird verworfen statt umgehaengt.
     */
    private function moveMarketingSends(Guest $keep, Guest $duplicate): int
    {
        $existing = MarketingSend::withoutGlobalScopes()
            ->where('guest_id', $keep->id)
            ->get(['marketing_campaign_id', 'reference'])
            ->map(fn ($s) => $s->marketing_campaign_id.'|'.$s->reference)
            ->all();

        $moved = 0;
        foreach (MarketingSend::withoutGlobalScopes()->where('guest_id', $duplicate->id)->get() as $send) {
            if (in_array($send->marketing_campaign_id.'|'.$send->reference, $existing, true)) {
                $send->delete();

                continue;
            }
            $send->update(['guest_id' => $keep->id]);
            $moved++;
        }

        return $moved;
    }

    /**
     * Counters add up, blanks get filled from the duplicate, and anything the
     * kept profile already says stays untouched.
     *
     * @return array<string, mixed>
     */
    private function mergedAttributes(Guest $keep, Guest $duplicate): array
    {
        $attributes = [];

        foreach (self::FILLABLE_BLANKS as $field) {
            $current = $keep->{$field};
            if (($current === null || $current === '') && ! in_array($duplicate->{$field}, [null, ''], true)) {
                $attributes[$field] = $duplicate->{$field};
            }
        }

        $keepVisits = (int) $keep->visit_count;
        $dupVisits = (int) $duplicate->visit_count;
        $attributes['visit_count'] = $keepVisits + $dupVisits;
        $attributes['no_show_count'] = (int) $keep->no_show_count + (int) $duplicate->no_show_count;
        $attributes['cancellation_count'] = (int) $keep->cancellation_count + (int) $duplicate->cancellation_count;
        $attributes['is_vip'] = (bool) $keep->is_vip || (bool) $duplicate->is_vip;

        // Weighted, so the average party size stays honest after the merge.
        $totalVisits = $keepVisits + $dupVisits;
        if ($totalVisits > 0) {
            $attributes['avg_party_size'] = round(
                ((float) $keep->avg_party_size * $keepVisits + (float) $duplicate->avg_party_size * $dupVisits) / $totalVisits,
                2
            );
        }

        $lastVisits = array_filter([$keep->last_visit_at, $duplicate->last_visit_at]);
        if ($lastVisits !== []) {
            $attributes['last_visit_at'] = collect($lastVisits)->max();
        }

        // Einwilligung: Es gewinnt die JUENGSTE Entscheidung, nicht das "ja".
        // Sonst wuerde ein Widerruf vom Vortag von einer zwei Jahre alten
        // Einwilligung des Duplikats ueberschrieben – der Gast waere gegen
        // seinen dokumentierten Willen wieder im Verteiler (Art. 7 Abs. 3).
        $keepAt = $keep->marketing_consent_at;
        $dupAt = $duplicate->marketing_consent_at;
        if ((bool) $keep->marketing_consent !== (bool) $duplicate->marketing_consent) {
            $duplicateIsNewer = $keepAt === null
                ? $dupAt !== null
                : ($dupAt !== null && $dupAt->greaterThan($keepAt));

            if ($duplicateIsNewer) {
                $attributes['marketing_consent'] = (bool) $duplicate->marketing_consent;
                $attributes['marketing_consent_at'] = $dupAt;
            }
        }

        if ($keep->email_verified_at === null && $duplicate->email_verified_at !== null
            && ($attributes['email'] ?? $keep->email) === $duplicate->email) {
            $attributes['email_verified_at'] = $duplicate->email_verified_at;
        }

        return $attributes;
    }
}

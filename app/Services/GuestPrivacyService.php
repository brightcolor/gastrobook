<?php

namespace App\Services;

use App\Models\Guest;
use Illuminate\Support\Facades\DB;

class GuestPrivacyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Full GDPR export of a guest profile incl. reservations and consents.
     */
    public function export(Guest $guest): array
    {
        return [
            'profile' => $guest->only([
                'first_name', 'last_name', 'email', 'phone', 'address_line1',
                'postal_code', 'city', 'country', 'birthday', 'locale',
                'preferences', 'allergies', 'accessibility_notes',
                'visit_count', 'no_show_count', 'cancellation_count',
                'last_visit_at', 'marketing_consent', 'created_at',
            ]),
            'reservations' => $guest->reservations()->withoutGlobalScope('tenant')->get()->map(fn ($r) => [
                'code' => $r->code,
                'date' => $r->reservation_date->toDateString(),
                'time' => $r->localStart()->format('H:i'),
                'party_size' => $r->party_size,
                'status' => $r->status->value,
                'source' => $r->source,
            ])->all(),
            'consents' => $guest->consents()->withoutGlobalScope('tenant')->get()->map(fn ($c) => [
                'type' => $c->type,
                'granted' => $c->granted,
                'channel' => $c->channel,
                'recorded_at' => $c->recorded_at?->toIso8601String(),
            ])->all(),
            'notes' => $guest->notes()->withoutGlobalScope('tenant')->where('is_sensitive', false)->pluck('body')->all(),
        ];
    }

    /**
     * Anonymize a guest: strip all personal data while keeping aggregate
     * statistics intact. Reservation snapshots are anonymized too.
     */
    public function anonymize(Guest $guest): void
    {
        DB::transaction(function () use ($guest) {
            $placeholder = 'Anonymisierter Gast #'.$guest->id;

            $guest->reservations()->withoutGlobalScope('tenant')->update([
                'guest_name_snapshot' => $placeholder,
                'guest_email_snapshot' => null,
                'guest_phone_snapshot' => null,
                'guest_note' => null,
                'allergy_note' => null,
            ]);

            $guest->notes()->withoutGlobalScope('tenant')->delete();

            $guest->update([
                'first_name' => null,
                'last_name' => $placeholder,
                'email' => null,
                'phone' => null,
                'phone_normalized' => null,
                'address_line1' => null,
                'postal_code' => null,
                'city' => null,
                'birthday' => null,
                'preferences' => null,
                'allergies' => null,
                'accessibility_notes' => null,
                'marketing_consent' => false,
                'anonymized' => true,
                'anonymized_at' => now(),
            ]);

            $this->audit->log('guest.anonymized', $guest, null, null, null, null, $guest->tenant_id);
        });
    }

    /**
     * Anonymize guests whose last activity is older than the tenant's
     * retention period. Called by the scheduled RunRetentionPolicies job.
     */
    public function runRetention(\App\Models\Tenant $tenant): int
    {
        $cutoff = now()->subMonths(max(1, $tenant->guest_retention_months));

        $guests = Guest::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('anonymized', false)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_visit_at')->where('created_at', '<', $cutoff)
                    ->orWhere('last_visit_at', '<', $cutoff);
            })
            ->whereDoesntHave('reservations', fn ($q) => $q->withoutGlobalScope('tenant')->where('start_at', '>', $cutoff))
            ->get();

        foreach ($guests as $guest) {
            $this->anonymize($guest);
        }

        return $guests->count();
    }
}

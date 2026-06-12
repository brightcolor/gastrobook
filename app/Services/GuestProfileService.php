<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Tenant;

class GuestProfileService
{
    /**
     * Find an existing guest by email/phone or create a new profile.
     * Dedupe order: exact email match, then normalized phone match.
     *
     * @param  array{name: string, email?: ?string, phone?: ?string, source?: string, allergies?: ?string, locale?: ?string}  $data
     */
    public function findOrCreate(Tenant $tenant, array $data): Guest
    {
        $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        $phoneNormalized = isset($data['phone']) ? preg_replace('/\D+/', '', $data['phone']) : null;

        $query = Guest::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('anonymized', false);

        $guest = null;
        if ($email) {
            $guest = (clone $query)->whereRaw('lower(email) = ?', [$email])->first();
        }
        if ($guest === null && $phoneNormalized) {
            $guest = (clone $query)->where('phone_normalized', $phoneNormalized)->first();
        }

        [$firstName, $lastName] = $this->splitName($data['name']);

        if ($guest === null) {
            $guest = Guest::create([
                'tenant_id' => $tenant->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'allergies' => $data['allergies'] ?? null,
                'locale' => $data['locale'] ?? null,
                'source' => $data['source'] ?? 'manual',
            ]);
        } else {
            // Fill gaps without overwriting curated data
            $guest->fill(array_filter([
                'email' => $guest->email ?: $email,
                'phone' => $guest->phone ?: ($data['phone'] ?? null),
                'allergies' => $guest->allergies ?: ($data['allergies'] ?? null),
            ]));
            if ($guest->isDirty()) {
                $guest->save();
            }
        }

        return $guest;
    }

    public function recordConsent(Guest $guest, string $type, bool $granted, string $channel, ?string $ip = null): GuestConsent
    {
        if ($type === 'marketing' || $type === 'newsletter') {
            $guest->update([
                'marketing_consent' => $granted,
                'marketing_consent_at' => $granted ? now() : $guest->marketing_consent_at,
            ]);
        }

        return GuestConsent::create([
            'tenant_id' => $guest->tenant_id,
            'guest_id' => $guest->id,
            'type' => $type,
            'granted' => $granted,
            'channel' => $channel,
            'ip_hash' => $ip ? substr(hash('sha256', $ip.config('app.key')), 0, 32) : null,
            'recorded_at' => now(),
        ]);
    }

    public function registerVisit(Guest $guest, int $partySize): void
    {
        $newCount = $guest->visit_count + 1;
        $avg = $guest->avg_party_size === null
            ? $partySize
            : round((($guest->avg_party_size * $guest->visit_count) + $partySize) / $newCount, 1);

        $guest->update([
            'visit_count' => $newCount,
            'last_visit_at' => now(),
            'avg_party_size' => $avg,
        ]);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);
        $pos = strrpos($name, ' ');
        if ($pos === false) {
            return [null, $name];
        }

        return [substr($name, 0, $pos), substr($name, $pos + 1)];
    }
}

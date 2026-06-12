<?php

namespace App\Services;

use App\Models\DepositRule;
use App\Models\Location;
use Carbon\CarbonImmutable;

class PaymentRequirementService
{
    /**
     * First matching active deposit rule for a booking, or null.
     */
    public function requirementFor(
        Location $location,
        CarbonImmutable $startLocal,
        int $partySize,
        ?int $eventId = null,
        ?int $roomId = null
    ): ?DepositRule {
        if (! $location->tenant->hasFeature('deposits_enabled')) {
            return null;
        }

        $weekday = $startLocal->dayOfWeekIso - 1;
        $time = $startLocal->format('H:i:s');

        return $this->query($location, $weekday, $time, $partySize, $eventId, $roomId);
    }

    private function query(Location $location, int $weekday, string $time, int $partySize, ?int $eventId, ?int $roomId): ?DepositRule
    {
        return DepositRule::withoutGlobalScope('tenant')
            ->where('tenant_id', $location->tenant_id)
            ->where('location_id', $location->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('min_party_size')->orWhere('min_party_size', '<=', $partySize))
            ->where(fn ($q) => $q->whereNull('from_time')->orWhere('from_time', '<=', $time))
            ->where(fn ($q) => $q->whereNull('until_time')->orWhere('until_time', '>=', $time))
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $eventId))
            ->where(fn ($q) => $q->whereNull('room_id')->orWhere('room_id', $roomId))
            ->orderByDesc('min_party_size')
            ->get()
            ->first(function (DepositRule $rule) use ($weekday) {
                $days = $rule->weekdays;

                return $days === null || $days === [] || in_array($weekday, $days, true);
            });
    }
}

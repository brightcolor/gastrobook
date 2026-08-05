<?php

namespace App\Services;

use App\Models\DepositRule;
use App\Models\Location;
use Carbon\CarbonImmutable;

class PaymentRequirementService
{
    /**
     * First matching active deposit rule for a booking, or null.
     *
     * @param  array<int, int>  $serviceIds  Salon: the services booked. A rule
     *                                       bound to a service only fires when
     *                                       that service is part of the appointment.
     */
    public function requirementFor(
        Location $location,
        CarbonImmutable $startLocal,
        int $partySize,
        ?int $eventId = null,
        ?int $roomId = null,
        array $serviceIds = []
    ): ?DepositRule {
        if (! $location->tenant->hasFeature('deposits_enabled')) {
            return null;
        }

        $weekday = $startLocal->dayOfWeekIso - 1;
        $time = $startLocal->format('H:i:s');

        return $this->query($location, $weekday, $time, $partySize, $eventId, $roomId, $serviceIds);
    }

    /**
     * @param  array<int, int>  $serviceIds
     */
    private function query(Location $location, int $weekday, string $time, int $partySize, ?int $eventId, ?int $roomId, array $serviceIds): ?DepositRule
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
            ->where(fn ($q) => $q->whereNull('service_id')->orWhereIn('service_id', $serviceIds))
            // A rule written for one service is the more specific statement and
            // wins over a blanket rule for the whole location.
            ->orderByRaw('CASE WHEN service_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('min_party_size')
            ->get()
            ->first(function (DepositRule $rule) use ($weekday) {
                $days = $rule->weekdays;

                return $days === null || $days === [] || in_array($weekday, $days, true);
            });
    }
}

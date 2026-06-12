<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\RestaurantTable;
use App\Models\TableCombination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TableAssignmentService
{
    /**
     * Find the best table (or combination) for a party in a UTC time window.
     *
     * @param  array{online?: bool, accessible?: bool, outdoor?: ?bool, room_id?: ?int, exclude_reservation_id?: ?int}  $options
     * @return ?array{table_ids: array<int>, names: array<string>, reason: string}
     */
    public function findTables(
        Location $location,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $partySize,
        array $options = []
    ): ?array {
        $online = $options['online'] ?? false;
        $buffer = (int) $location->effectiveSettings()->buffer_minutes;
        $windowStart = $startUtc->subMinutes($buffer);
        $windowEnd = $endUtc->addMinutes($buffer);

        $busyTableIds = $this->busyTableIds($location, $windowStart, $windowEnd, $options['exclude_reservation_id'] ?? null);
        $blockedRoomIds = $this->blockedRoomIds($location, $windowStart, $windowEnd);

        $tables = $location->tables()
            ->where('is_active', true)
            ->when($online, fn ($q) => $q->where('online_bookable', true))
            ->when(isset($options['room_id']) && $options['room_id'], fn ($q) => $q->where('room_id', $options['room_id']))
            ->when($options['accessible'] ?? false, fn ($q) => $q->where('accessible', true))
            ->when(array_key_exists('outdoor', $options) && $options['outdoor'] !== null, fn ($q) => $q->where('outdoor', $options['outdoor']))
            ->get()
            ->reject(fn (RestaurantTable $t) => in_array($t->id, $busyTableIds, true))
            ->reject(fn (RestaurantTable $t) => in_array($t->room_id, $blockedRoomIds, true));

        // 1. Best single table
        $single = $this->bestSingleTable($tables, $partySize);
        if ($single !== null) {
            return [
                'table_ids' => [$single->id],
                'names' => [$single->name],
                'reason' => sprintf(
                    'smallest_fit: table %s (%d-%d seats) for party of %d',
                    $single->name, $single->min_capacity, $single->max_capacity, $partySize
                ),
            ];
        }

        // 2. Predefined combinations
        $combo = $this->bestCombination($location, $tables, $partySize, $online);
        if ($combo !== null) {
            return $combo;
        }

        return null;
    }

    /**
     * All free tables for a window — used for walk-in screens and the floor plan.
     *
     * @return Collection<int, RestaurantTable>
     */
    public function freeTables(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc): Collection
    {
        $busy = $this->busyTableIds($location, $startUtc, $endUtc, null);
        $blockedRooms = $this->blockedRoomIds($location, $startUtc, $endUtc);

        return $location->tables()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->reject(fn (RestaurantTable $t) => in_array($t->id, $busy, true))
            ->reject(fn (RestaurantTable $t) => in_array($t->room_id, $blockedRooms, true))
            ->values();
    }

    /**
     * @return array<int>
     */
    public function busyTableIds(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?int $excludeReservationId): array
    {
        $reserved = $location->reservations()
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $endUtc)
            ->where('end_at', '>', $startUtc)
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->with('tables:restaurant_tables.id')
            ->get()
            ->flatMap(fn ($r) => $r->tables->pluck('id'))
            ->all();

        $blocked = $location->tables()->getQuery()
            ->join('table_blocks', 'table_blocks.restaurant_table_id', '=', 'restaurant_tables.id')
            ->where('table_blocks.starts_at', '<', $endUtc)
            ->where('table_blocks.ends_at', '>', $startUtc)
            ->pluck('restaurant_tables.id')
            ->all();

        return array_values(array_unique([...$reserved, ...$blocked]));
    }

    /**
     * Room ids fully blocked by blackout periods in the window.
     *
     * @return array<int>
     */
    private function blockedRoomIds(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        return $location->blackoutPeriods()
            ->whereNotNull('room_id')
            ->whereNull('reduce_covers_to')
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->pluck('room_id')
            ->all();
    }

    /**
     * Smallest fitting table; ties broken by preferred_capacity match, then priority.
     *
     * @param  Collection<int, RestaurantTable>  $tables
     */
    private function bestSingleTable(Collection $tables, int $partySize): ?RestaurantTable
    {
        return $tables
            ->filter(fn (RestaurantTable $t) => $t->fitsParty($partySize))
            ->sortBy([
                fn (RestaurantTable $a, RestaurantTable $b) => ($a->max_capacity - $partySize) <=> ($b->max_capacity - $partySize),
                fn (RestaurantTable $a, RestaurantTable $b) => (int) ($b->preferred_capacity === $partySize) <=> (int) ($a->preferred_capacity === $partySize),
                fn (RestaurantTable $a, RestaurantTable $b) => $a->priority <=> $b->priority,
            ])
            ->first();
    }

    /**
     * @param  Collection<int, RestaurantTable>  $freeTables
     * @return ?array{table_ids: array<int>, names: array<string>, reason: string}
     */
    private function bestCombination(Location $location, Collection $freeTables, int $partySize, bool $online): ?array
    {
        $freeIds = $freeTables->pluck('id')->all();

        $combos = $location->tableCombinations()
            ->where('is_active', true)
            ->when($online, fn ($q) => $q->where('online_bookable', true))
            ->where('min_capacity', '<=', $partySize)
            ->where('max_capacity', '>=', $partySize)
            ->orderBy('priority')
            ->orderBy('max_capacity')
            ->with('tables')
            ->get();

        /** @var TableCombination $combo */
        foreach ($combos as $combo) {
            $memberIds = $combo->tables->pluck('id')->all();
            if ($memberIds !== [] && count(array_diff($memberIds, $freeIds)) === 0) {
                return [
                    'table_ids' => $memberIds,
                    'names' => $combo->tables->pluck('name')->all(),
                    'reason' => sprintf(
                        'combination: %s (%d-%d seats) for party of %d',
                        $combo->name, $combo->min_capacity, $combo->max_capacity, $partySize
                    ),
                ];
            }
        }

        return null;
    }
}

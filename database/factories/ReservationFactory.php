<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);

        return [
            'tenant_id' => fn (array $attrs) => Location::withoutGlobalScope('tenant')->find($attrs['location_id'])->tenant_id,
            'location_id' => Location::factory(),
            'party_size' => fake()->numberBetween(1, 6),
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->utc()->addMinutes(120),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'manual',
            'guest_name_snapshot' => fake()->name(),
            'guest_email_snapshot' => fake()->safeEmail(),
        ];
    }
}

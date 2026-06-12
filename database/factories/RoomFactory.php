<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    public function definition(): array
    {
        $location = Location::factory();

        return [
            'tenant_id' => fn (array $attrs) => Location::withoutGlobalScope('tenant')->find($attrs['location_id'])->tenant_id,
            'location_id' => $location,
            'name' => 'Raum '.fake()->unique()->numberBetween(1, 999),
            'is_outdoor' => false,
            'is_active' => true,
            'online_bookable' => true,
        ];
    }
}

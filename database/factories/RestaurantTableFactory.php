<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestaurantTableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attrs) => Room::withoutGlobalScope('tenant')->find($attrs['room_id'])->tenant_id,
            'location_id' => fn (array $attrs) => Room::withoutGlobalScope('tenant')->find($attrs['room_id'])->location_id,
            'room_id' => Room::factory(),
            'name' => 'T'.fake()->unique()->numberBetween(1, 9999),
            'min_capacity' => 1,
            'max_capacity' => 4,
            'is_active' => true,
            'online_bookable' => true,
            'joinable' => true,
            'priority' => 100,
        ];
    }
}

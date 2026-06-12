<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LocationFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Restaurant '.fake()->lastName();

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'type' => 'restaurant',
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
            'locale' => 'de',
            'is_active' => true,
            'online_booking_enabled' => true,
        ];
    }
}

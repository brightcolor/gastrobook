<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'default_locale' => 'de',
            'default_currency' => 'EUR',
            'guest_retention_months' => 36,
        ];
    }
}

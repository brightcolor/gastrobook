<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'plan-'.fake()->unique()->word(),
            'name' => 'Professional',
            'price_monthly_minor' => 4900,
            'currency' => 'EUR',
            'limits' => [
                'max_locations' => 3,
                'max_users' => 20,
                'max_tables' => 100,
                'max_seats' => null,
                'max_reservations_per_month' => null,
                'max_events' => 20,
            ],
            'features' => [
                'api_enabled' => true,
                'webhooks_enabled' => true,
                'deposits_enabled' => true,
                'waitlist_enabled' => true,
                'feedback_enabled' => true,
                'custom_domain_enabled' => false,
                'remove_branding' => true,
                'advanced_reports' => true,
            ],
            'trial_days' => 0,
            'is_active' => true,
        ];
    }
}

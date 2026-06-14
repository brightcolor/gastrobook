<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catalog data, safe to run in production (idempotent by plan key).
 * Required for self-service signup, which needs the trial plan.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'trial' => ['Trial', 0, ['max_locations' => 1, 'max_users' => 2, 'max_tables' => 10, 'max_reservations_per_month' => 200], ['waitlist_enabled' => true, 'feedback_enabled' => false, 'api_enabled' => false, 'webhooks_enabled' => false, 'deposits_enabled' => false], 30],
            'starter' => ['Starter', 1900, ['max_locations' => 1, 'max_users' => 3, 'max_tables' => 30, 'max_seats' => 80], ['waitlist_enabled' => false, 'feedback_enabled' => false, 'api_enabled' => false, 'webhooks_enabled' => false, 'deposits_enabled' => false], 0],
            'professional' => ['Professional', 3900, ['max_locations' => 1, 'max_users' => 10, 'max_tables' => 100], ['waitlist_enabled' => true, 'feedback_enabled' => true, 'api_enabled' => true, 'webhooks_enabled' => true, 'deposits_enabled' => true, 'advanced_reports' => true], 0],
            'multi_location' => ['Multi-Location', 5900, ['max_locations' => 10, 'max_users' => 50, 'max_tables' => 1000], ['waitlist_enabled' => true, 'feedback_enabled' => true, 'api_enabled' => true, 'webhooks_enabled' => true, 'deposits_enabled' => true, 'advanced_reports' => true, 'multi_location_reports' => true], 0],
            'enterprise' => ['Enterprise', 0, [], ['waitlist_enabled' => true, 'feedback_enabled' => true, 'api_enabled' => true, 'webhooks_enabled' => true, 'deposits_enabled' => true, 'advanced_reports' => true, 'multi_location_reports' => true, 'custom_domain_enabled' => true, 'remove_branding' => true], 0],
        ];

        $sort = 0;
        foreach ($definitions as $key => [$name, $price, $limits, $features, $trialDays]) {
            Plan::updateOrCreate(['key' => $key], [
                'name' => $name,
                'price_monthly_minor' => $price,
                'limits' => $limits,
                'features' => $features,
                'trial_days' => $trialDays,
                'is_active' => true,
                'sort_order' => $sort++,
            ]);
        }
    }
}

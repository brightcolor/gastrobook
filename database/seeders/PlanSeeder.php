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
        // Every plan ships the FULL feature set. Plans differ only by capacity
        // (locations & tables) – the revenue-relevant limits. Users are
        // unlimited on every plan (no max_users key).
        $allFeatures = [
            'waitlist_enabled' => true,
            'feedback_enabled' => true,
            'api_enabled' => true,
            'webhooks_enabled' => true,
            'deposits_enabled' => true,
            'advanced_reports' => true,
            'multi_location_reports' => true,
            'custom_domain_enabled' => true,
            'remove_branding' => true,
        ];

        $definitions = [
            // key => [name, price_minor, limits, trialDays]
            'trial' => ['Trial', 0, ['max_locations' => 1, 'max_tables' => 15], 30],
            'starter' => ['Starter', 1900, ['max_locations' => 1, 'max_tables' => 15], 0],
            'professional' => ['Professional', 3900, ['max_locations' => 1, 'max_tables' => 50], 0],
            'multi_location' => ['Multi-Location', 5900, ['max_locations' => 5, 'max_tables' => 200], 0],
            'enterprise' => ['Enterprise', 0, [], 0], // unlimited locations & tables, on request
        ];

        $sort = 0;
        foreach ($definitions as $key => [$name, $price, $limits, $trialDays]) {
            $features = $allFeatures;
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

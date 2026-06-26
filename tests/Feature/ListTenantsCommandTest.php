<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_tenants_with_status_filter(): void
    {
        Tenant::factory()->create(['name' => 'Aktiv GmbH', 'status' => 'active', 'plan_id' => Plan::factory()]);
        Tenant::factory()->create(['name' => 'Gesperrt GmbH', 'status' => 'suspended', 'plan_id' => Plan::factory()]);

        $this->artisan('swayy:tenants', ['--status' => 'active'])
            ->expectsOutputToContain('Aktiv GmbH')
            ->doesntExpectOutputToContain('Gesperrt GmbH')
            ->assertSuccessful();
    }

    public function test_search_matches_name(): void
    {
        Tenant::factory()->create(['name' => 'Trattoria Luna', 'plan_id' => Plan::factory()]);

        $this->artisan('swayy:tenants', ['--search' => 'Luna'])
            ->expectsOutputToContain('Trattoria Luna')
            ->assertSuccessful();
    }
}

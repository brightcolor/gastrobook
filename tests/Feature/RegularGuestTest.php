<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RegularGuestTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_is_regular_by_visit_count_and_vip(): void
    {
        config(['swayy.regular_after_visits' => 5]);
        $setup = $this->createTenantSetup();
        $tid = $setup['tenant']->id;

        $occasional = Guest::factory()->create(['tenant_id' => $tid, 'visit_count' => 2, 'is_vip' => false]);
        $frequent = Guest::factory()->create(['tenant_id' => $tid, 'visit_count' => 6, 'is_vip' => false]);
        $vip = Guest::factory()->create(['tenant_id' => $tid, 'visit_count' => 0, 'is_vip' => true]);

        $this->assertFalse($occasional->isRegular());
        $this->assertTrue($frequent->isRegular());
        $this->assertTrue($vip->isRegular());
    }
}

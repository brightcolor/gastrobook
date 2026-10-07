<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UiSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(3200, config('swayy.ui.toast_ms'));
        $this->assertSame(1200, config('swayy.ui.reload_delay_ms'));
        $this->assertSame('#0f766e', config('swayy.ui.default_brand_color'));
    }

    public function test_layout_hands_the_toast_duration_to_the_browser(): void
    {
        config(['swayy.ui.toast_ms' => 5000, 'swayy.ui.reload_delay_ms' => 400]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin')
            ->assertOk()
            ->assertSee('"toastMs":5000', false)
            ->assertSee('"reloadDelayMs":400', false)
            ->assertSee('"network":', false);
    }
}

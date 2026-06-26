<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_new_signup_redirects_to_onboarding(): void
    {
        $response = $this->post('/register', [
            'restaurant_name' => 'Trattoria Bella',
            'name' => 'Maria Rossi',
            'email' => 'maria@bella.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'privacy_accepted' => '1',
        ]);

        $response->assertRedirect(route('admin.onboarding.show'));

        $tenant = Tenant::where('slug', 'trattoria-bella')->firstOrFail();
        $this->assertNull($tenant->onboarding_completed_at);
    }

    public function test_onboarding_page_loads_for_pending_tenant(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $this->actingAs($user);

        $response = $this->get(route('admin.onboarding.show'));
        $response->assertOk();
        $response->assertViewIs('admin.onboarding.index');
    }

    public function test_completed_tenant_is_redirected_away_from_onboarding(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $tenant->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->get(route('admin.onboarding.show'));
        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_complete_marks_tenant_as_done_and_redirects_to_dashboard(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $this->actingAs($user);

        $response = $this->post(route('admin.onboarding.complete'));
        $response->assertRedirect(route('admin.dashboard'));

        $this->assertNotNull($tenant->fresh()->onboarding_completed_at);
    }

    public function test_dashboard_shows_banner_when_onboarding_pending(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $this->assertNull($tenant->onboarding_completed_at);
        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));
        $response->assertSee('Setup starten');
    }

    public function test_dashboard_hides_banner_after_onboarding_complete(): void
    {
        [$tenant, $user] = $this->createTenantWithOwner();
        $tenant->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));
        $response->assertDontSee('Setup starten');
    }

    private function createTenantWithOwner(): array
    {
        $this->post('/register', [
            'restaurant_name' => 'Test Bistro',
            'name' => 'Owner',
            'email' => 'owner@bistro.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'privacy_accepted' => '1',
        ]);
        auth()->logout();

        $tenant = Tenant::where('slug', 'test-bistro')->firstOrFail();
        $user = User::where('email', 'owner@bistro.test')->firstOrFail();

        return [$tenant, $user];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_signup_creates_trial_tenant_with_owner_and_location(): void
    {
        $response = $this->post('/register', [
            'restaurant_name' => 'Trattoria Bella',
            'name' => 'Maria Rossi',
            'email' => 'maria@bella.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'privacy_accepted' => '1',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        $tenant = Tenant::where('slug', 'trattoria-bella')->firstOrFail();
        $this->assertSame('trial', $tenant->plan?->key);
        $this->assertSame('active', $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);

        $user = User::where('email', 'maria@bella.test')->firstOrFail();
        $this->assertSame($tenant->id, $user->current_tenant_id);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'tenant_owner',
        ]);
        $this->assertDatabaseHas('locations', [
            'tenant_id' => $tenant->id,
            'slug' => 'trattoria-bella',
        ]);
    }

    public function test_signup_rejects_existing_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'restaurant_name' => 'Zweites Restaurant',
            'name' => 'Neuer Nutzer',
            'email' => 'taken@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'privacy_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('tenants', ['name' => 'Zweites Restaurant']);
    }

    public function test_signup_requires_privacy_acceptance(): void
    {
        $response = $this->post('/register', [
            'restaurant_name' => 'Ohne Consent',
            'name' => 'Test',
            'email' => 'consent@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ]);

        $response->assertSessionHasErrors('privacy_accepted');
        $this->assertGuest();
    }

    public function test_duplicate_restaurant_names_get_unique_slugs(): void
    {
        foreach (['a@x.test', 'b@x.test'] as $email) {
            $this->post('/register', [
                'restaurant_name' => 'Gasthaus Sonne',
                'name' => 'Wirt',
                'email' => $email,
                'password' => 'super-secret-pw',
                'password_confirmation' => 'super-secret-pw',
                'privacy_accepted' => '1',
            ]);
            auth()->logout();
        }

        $this->assertSame(
            2,
            Tenant::whereIn('slug', ['gasthaus-sonne', 'gasthaus-sonne-2'])->count()
        );
    }

    public function test_honeypot_blocks_bot_signup(): void
    {
        $this->post('/register', [
            'restaurant_name' => 'Bot Bistro',
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'privacy_accepted' => '1',
            'website' => 'http://spam.example',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tenants', ['name' => 'Bot Bistro']);
    }
}

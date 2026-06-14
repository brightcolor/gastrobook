<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin_from_options(): void
    {
        $this->artisan('swayy:create-admin', [
            '--email' => 'chef@example.com',
            '--password' => 'sehr-geheim-123',
            '--name' => 'Chef',
        ])->assertSuccessful();

        $user = User::where('email', 'chef@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('sehr-geheim-123', $user->password));
    }

    public function test_if_missing_skips_when_super_admin_exists(): void
    {
        User::factory()->create(['saas_role' => 'super_admin']);

        $this->artisan('swayy:create-admin', [
            '--email' => 'second@example.com',
            '--password' => 'sehr-geheim-123',
            '--if-missing' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('swayy:create-admin', [
            '--email' => 'kurz@example.com',
            '--password' => 'kurz',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'kurz@example.com']);
    }

    public function test_force_promotes_existing_user(): void
    {
        User::factory()->create(['email' => 'bestand@example.com', 'saas_role' => null]);

        $this->artisan('swayy:create-admin', [
            '--email' => 'bestand@example.com',
            '--password' => 'sehr-geheim-123',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::where('email', 'bestand@example.com')->first()->isSuperAdmin());
    }
}

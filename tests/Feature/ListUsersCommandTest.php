<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ListUsersCommandTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_lists_users_with_membership(): void
    {
        $setup = $this->createTenantSetup();
        $this->createMember($setup['tenant'], 'tenant_owner'); // random name/email
        User::factory()->create(['name' => 'Plattform Admin', 'saas_role' => 'super_admin']);
        $this->clearTenantContext();

        $this->artisan('swayy:users')
            ->expectsOutputToContain('Plattform Admin')
            ->expectsOutputToContain($setup['tenant']->name)
            ->assertSuccessful();
    }

    public function test_saas_filter_excludes_tenant_only_users(): void
    {
        $setup = $this->createTenantSetup();
        $member = $this->createMember($setup['tenant'], 'host');
        User::factory()->create(['name' => 'Nur Admin', 'saas_role' => 'support_admin']);
        $this->clearTenantContext();

        $this->artisan('swayy:users', ['--saas' => true])
            ->expectsOutputToContain('Nur Admin')
            ->doesntExpectOutputToContain($member->email)
            ->assertSuccessful();
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('swayy:users', ['--tenant' => 'gibt-es-nicht'])->assertFailed();
    }
}

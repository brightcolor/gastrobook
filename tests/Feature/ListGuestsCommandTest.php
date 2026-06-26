<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ListGuestsCommandTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_lists_guests_and_hides_anonymized_by_default(): void
    {
        $setup = $this->createTenantSetup();
        $tenant = $setup['tenant'];

        Guest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'first_name' => 'Max', 'last_name' => 'Mustermann',
            'email' => 'max@example.test', 'anonymized' => false,
        ]);
        Guest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'first_name' => 'Gelöscht', 'last_name' => 'Anonym',
            'anonymized' => true,
        ]);
        $this->clearTenantContext();

        $this->artisan('swayy:guests', ['--tenant' => $tenant->id])
            ->expectsOutputToContain('Mustermann')
            ->doesntExpectOutputToContain('Gelöscht')
            ->assertSuccessful();
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('swayy:guests', ['--tenant' => 'gibt-es-nicht'])
            ->assertFailed();
    }
}

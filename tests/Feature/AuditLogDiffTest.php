<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLogDiffTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_field_changes_resolves_old_and_new_values(): void
    {
        $log = new AuditLog([
            'old_values' => ['status' => 'confirmed', 'party_size' => 2, 'note' => 'alt'],
            'new_values' => ['status' => 'seated', 'party_size' => 2, 'is_vip' => true],
        ]);

        $changes = collect($log->fieldChanges())->keyBy('field');

        // changed: from → to
        $this->assertSame('confirmed', $changes['status']['from']);
        $this->assertSame('seated', $changes['status']['to']);
        // unchanged fields are dropped
        $this->assertArrayNotHasKey('party_size', $changes->all());
        // removed: from without to
        $this->assertSame('alt', $changes['note']['from']);
        $this->assertNull($changes['note']['to']);
        // added: bool formatted as ja/nein
        $this->assertNull($changes['is_vip']['from']);
        $this->assertSame('ja', $changes['is_vip']['to']);
    }

    public function test_audit_page_renders_diff_lines(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        AuditLog::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $admin->id,
            'action' => 'guest.updated',
            'old_values' => ['last_name' => 'Meyer'],
            'new_values' => ['last_name' => 'Meier'],
            'created_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/audit')
            ->assertOk()
            ->assertSee('last_name')
            ->assertSee('Meyer')
            ->assertSee('Meier');
    }
}

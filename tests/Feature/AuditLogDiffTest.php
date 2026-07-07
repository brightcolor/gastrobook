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

    public function test_field_changes_are_humanised(): void
    {
        $log = new AuditLog([
            'action' => 'reservation.status_changed',
            'old_values' => ['status' => 'confirmed', 'party_size' => 2, 'guest_note' => 'alt'],
            'new_values' => ['status' => 'seated', 'party_size' => 2, 'is_vip' => true, 'price_minor' => 2500],
        ]);

        $changes = collect($log->fieldChanges())->keyBy('field');

        // Status value is translated, not the raw enum code.
        $this->assertSame('Status', $changes['status']['label']);
        $this->assertSame('Bestätigt', $changes['status']['from']);
        $this->assertSame('Am Tisch', $changes['status']['to']);
        // Unchanged fields dropped.
        $this->assertArrayNotHasKey('party_size', $changes->all());
        // Removed value: from without to.
        $this->assertSame('Gastnotiz', $changes['guest_note']['label']);
        $this->assertNull($changes['guest_note']['to']);
        // Boolean → Ja/Nein.
        $this->assertSame('VIP', $changes['is_vip']['label']);
        $this->assertSame('Ja', $changes['is_vip']['to']);
        // *_minor → euro amount.
        $this->assertSame('Preis', $changes['price_minor']['label']);
        $this->assertSame('25,00 €', $changes['price_minor']['to']);
    }

    public function test_action_label_is_plain_german(): void
    {
        $this->assertSame('Reservierung: Status geändert', (new AuditLog(['action' => 'reservation.status_changed']))->actionLabel());
        $this->assertSame('Tisch gelöscht', (new AuditLog(['action' => 'table.deleted']))->actionLabel());
        $this->assertSame('Gast geändert', (new AuditLog(['action' => 'guest.updated']))->actionLabel());
        $this->assertSame('Leistung angelegt', (new AuditLog(['action' => 'service.created']))->actionLabel());
    }

    public function test_audit_page_renders_readable_diff(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        AuditLog::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $admin->id,
            'action' => 'reservation.status_changed',
            'old_values' => ['status' => 'confirmed'],
            'new_values' => ['status' => 'seated'],
            'created_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Reservierung: Status geändert')
            ->assertSee('Vorher')
            ->assertSee('Nachher')
            ->assertSee('Bestätigt')
            ->assertSee('Am Tisch')
            ->assertDontSee('reservation.status_changed'); // no raw technical key
    }
}

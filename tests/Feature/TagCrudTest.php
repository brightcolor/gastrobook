<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TagCrudTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_full_tag_crud_including_delete(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Create
        $create = $this->actingAs($admin)->postJson('/admin/tags', [
            'name' => 'VIP', 'color' => '#ff0000',
        ]);
        $create->assertCreated();
        $tagId = $create->json('id');
        $this->assertNotNull($tagId);

        // Update
        $this->actingAs($admin)->putJson("/admin/tags/{$tagId}", [
            'name' => 'Stammgast', 'color' => '#00ff00',
        ])->assertOk();
        $this->assertSame('Stammgast', Tag::find($tagId)->name);

        // Delete — regression guard: previously threw a 500 because the
        // AuditLogger call passed the Tag model into the $oldValues array slot.
        $this->actingAs($admin)->deleteJson("/admin/tags/{$tagId}")->assertOk();
        $this->assertNull(Tag::find($tagId));

        // The delete must be recorded in the audit log.
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $setup['tenant']->id,
            'action' => 'tag.deleted',
        ]);
    }

    public function test_cannot_delete_system_tag(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tag = Tag::create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'System', 'color' => '#111111',
            'scope' => 'reservation', 'is_system' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->deleteJson("/admin/tags/{$tag->id}")->assertForbidden();
        $this->assertNotNull($tag->fresh());
    }
}

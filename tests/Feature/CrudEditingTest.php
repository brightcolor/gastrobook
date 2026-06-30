<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DepositRule;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CrudEditingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_deposit_rule_can_be_edited(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = DepositRule::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Alt', 'type' => 'deposit', 'amount_per_person_minor' => 1000,
            'currency' => 'EUR', 'payment_deadline_minutes' => 60,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/settings/deposit-rules/{$rule->id}", [
            'name' => 'Neu ab 6', 'min_party_size' => 6, 'amount_per_person' => 15.50,
            'from_time' => '18:00', 'payment_deadline_minutes' => 120,
        ])->assertRedirect();

        $rule->refresh();
        $this->assertSame('Neu ab 6', $rule->name);
        $this->assertSame(6, $rule->min_party_size);
        $this->assertSame(1550, $rule->amount_per_person_minor);
        $this->assertSame(120, $rule->payment_deadline_minutes);
    }

    public function test_deposit_rule_of_other_location_is_404(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $rule = DepositRule::create([
            'tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id,
            'name' => 'Fremd', 'type' => 'deposit', 'amount_per_person_minor' => 1000,
            'currency' => 'EUR', 'payment_deadline_minutes' => 60,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/settings/deposit-rules/{$rule->id}", [
            'name' => 'Hack', 'amount_per_person' => 1,
        ])->assertNotFound();
    }

    public function test_tag_can_be_renamed(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tag = Tag::create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#ff0000', 'scope' => 'reservation',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson("/admin/tags/{$tag->id}", [
            'name' => 'Stammgast', 'color' => '#00aa55',
        ])->assertOk()->assertJsonFragment(['name' => 'Stammgast', 'color' => '#00aa55']);

        $this->assertSame('Stammgast', $tag->fresh()->name);
    }

    public function test_system_tag_cannot_be_renamed(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tag = Tag::create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'System', 'color' => '#111111',
            'scope' => 'reservation', 'is_system' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson("/admin/tags/{$tag->id}", [
            'name' => 'X', 'color' => '#222222',
        ])->assertForbidden();
    }

    public function test_table_can_be_edited_from_the_floorplan(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson("/admin/floorplan/tables/{$table->id}", [
            'name' => 'Fenster 1', 'min_capacity' => 2, 'max_capacity' => 6,
            'online_bookable' => true, 'joinable' => true, 'outdoor' => false, 'accessible' => true,
        ])->assertOk()->assertJsonPath('table.name', 'Fenster 1');

        $table->refresh();
        $this->assertSame('Fenster 1', $table->name);
        $this->assertSame(6, $table->max_capacity);
        $this->assertTrue($table->joinable);
        $this->assertTrue($table->accessible);
    }

    public function test_combination_can_be_edited(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach ($setup['tables'] as $t) {
            $t->update(['joinable' => true]);
        }
        $combo = $setup['location']->tableCombinations()->create([
            'tenant_id' => $setup['tenant']->id, 'name' => 'Alt', 'min_capacity' => 4, 'max_capacity' => 6,
        ]);
        $combo->tables()->sync([$setup['tables'][0]->id, $setup['tables'][1]->id]);
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson("/admin/settings/combinations/{$combo->id}", [
            'name' => 'Große Tafel', 'min_capacity' => 6, 'max_capacity' => 12,
            'table_ids' => [$setup['tables'][1]->id, $setup['tables'][2]->id],
        ])->assertOk()->assertJsonPath('combination.name', 'Große Tafel');

        $combo->refresh()->load('tables');
        $this->assertSame('Große Tafel', $combo->name);
        $this->assertSame(12, $combo->max_capacity);
        $this->assertEqualsCanonicalizing(
            [$setup['tables'][1]->id, $setup['tables'][2]->id],
            $combo->tables->pluck('id')->all()
        );
    }
}

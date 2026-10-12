<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Gästegrenze („Weniger Gäste“) wirkt in jedem Kapazitätsmodus, für den
 * ganzen Betrieb und für einen einzelnen Raum (siehe GuestLimitBlackoutTest).
 * Die Seite Ausnahmen nimmt deshalb jede dieser Kombinationen an; der Raum
 * einer Sperre wird als Sperre des Raums gespeichert.
 */
class BlackoutClarityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const URL = '/admin/settings/exceptions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function limit(array $extra = []): array
    {
        return array_merge([
            'from' => '2026-12-27',
            'label' => 'Geburtstag Paul',
            'effects' => ['limit'],
            'limit_covers' => 20,
            'limit_from' => '14:00',
            'limit_until' => '18:00',
        ], $extra);
    }

    public function test_a_guest_limit_for_a_single_room_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->postJson(self::URL, $this->limit(['limit_room_id' => $setup['room']->id]))
            ->assertOk()
            ->assertJsonPath('message', 'Ausnahme gespeichert.');

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertSame($setup['room']->id, $bo->room_id);
        $this->assertSame(20, (int) $bo->reduce_covers_to);
        $this->assertSame('Geburtstag Paul', $bo->reason);
    }

    public function test_a_guest_limit_in_table_mode_is_accepted(): void
    {
        $setup = $this->createTenantSetup(); // Vorgabe: nach Tischen
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->postJson(self::URL, $this->limit(['limit_covers' => 12]))
            ->assertOk()
            ->assertJsonPath('message', 'Ausnahme gespeichert.');

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertNull($bo->room_id);
        $this->assertSame(12, (int) $bo->reduce_covers_to);
    }

    public function test_a_single_room_lock_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson(self::URL, [
            'from' => '2026-12-27', 'label' => 'Geburtstag Paul', 'effects' => ['lock'],
            'lock_rooms' => [$setup['room']->id], 'lock_from' => '14:00', 'lock_until' => '18:00',
        ])->assertOk();

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertSame($setup['room']->id, $bo->room_id);
        $this->assertNull($bo->reduce_covers_to);
    }

    public function test_a_guest_limit_for_the_whole_location_in_person_mode_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['capacity_mode' => 'person']);
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson(self::URL, $this->limit())->assertOk();

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertNull($bo->room_id);
        $this->assertSame(20, (int) $bo->reduce_covers_to);
    }
}

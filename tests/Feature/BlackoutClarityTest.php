<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Sperrzeit mit „weniger Gäste" wirkt in jedem Kapazitätsmodus, für den
 * ganzen Betrieb und für einen einzelnen Raum (siehe GuestLimitBlackoutTest).
 * Der Controller nimmt deshalb jede dieser Kombinationen an.
 */
class BlackoutClarityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const URL = '/admin/settings/blackouts';

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'starts_at' => '2026-12-27T14:00',
            'ends_at' => '2026-12-27T18:00',
            'reason' => 'Geburtstag Paul',
        ], $extra);
    }

    public function test_a_guest_limit_for_a_single_room_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->postJson(self::URL, $this->payload([
                'room_id' => $setup['room']->id,
                'reduce_covers_to' => 20,
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Sperrzeit gespeichert.');

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertSame($setup['room']->id, $bo->room_id);
        $this->assertSame(20, (int) $bo->reduce_covers_to);
    }

    public function test_a_guest_limit_in_table_mode_is_accepted(): void
    {
        $setup = $this->createTenantSetup(); // Vorgabe: nach Tischen
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->postJson(self::URL, $this->payload(['reduce_covers_to' => 12]))
            ->assertOk()
            ->assertJsonPath('message', 'Sperrzeit gespeichert.');

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertNull($bo->room_id);
        $this->assertSame(12, (int) $bo->reduce_covers_to);
    }

    public function test_a_full_closure_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson(self::URL, $this->payload());

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertNull($bo->room_id);
        $this->assertNull($bo->reduce_covers_to);
    }

    public function test_a_single_room_closure_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson(self::URL, $this->payload([
            'room_id' => $setup['room']->id,
        ]));

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

        $this->actingAs($admin)->postJson(self::URL, $this->payload([
            'reduce_covers_to' => 20,
        ]));

        $bo = BlackoutPeriod::withoutGlobalScopes()->sole();
        $this->assertNull($bo->room_id);
        $this->assertSame(20, (int) $bo->reduce_covers_to);
    }

    public function test_the_settings_page_flags_an_ineffective_blackout(): void
    {
        $setup = $this->createTenantSetup(); // Vorgabe: nach Tischen
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        // Die bisherige Karte markiert Raum + Gästezahl weiter mit „wirkt
        // nicht". Die Liste „Ausnahmen" auf new-ui löst diese Karte ab.
        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $setup['room']->id,
            'starts_at' => '2026-12-27 13:00:00',
            'ends_at' => '2026-12-27 17:00:00',
            'reduce_covers_to' => 20,
            'reason' => 'Geburtstag Paul',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('wirkt nicht', false);
    }

    public function test_the_form_marks_the_guest_limit_unavailable_in_table_mode(): void
    {
        $setup = $this->createTenantSetup(); // nach Tischen
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('nach Plätzen bucht', false);
    }
}

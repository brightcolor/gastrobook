<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeasonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Buchbare Saisons im Admin anlegen, löschen und gegen Fehleingaben schützen.
 */
class SeasonAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_season_window_can_be_created(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/settings/seasons', [
            'label' => 'Sommersaison',
            'start_month' => 4, 'start_day' => 1,
            'end_month' => 10, 'end_day' => 31,
        ])->assertOk();

        $season = SeasonPeriod::withoutGlobalScopes()->sole();
        $this->assertSame('Sommersaison', $season->label);
        $this->assertSame(4, $season->start_month);
        $this->assertSame(31, $season->end_day);
        $this->assertSame($setup['location']->id, $season->location_id);
    }

    public function test_an_impossible_day_is_refused_with_a_clear_message(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/settings/seasons', [
            'start_month' => 2, 'start_day' => 31, // 31. Februar gibt es nicht
            'end_month' => 10, 'end_day' => 31,
        ])->assertStatus(422)
            ->assertJsonPath(
                'errors.start_day.0',
                'Diesen Tag gibt es in dem Monat nicht. Bitte einen gültigen Tag wählen.'
            );

        $this->assertDatabaseCount('season_periods', 0);
    }

    public function test_a_season_can_be_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $season = $setup['location']->seasonPeriods()->create([
            'tenant_id' => $setup['tenant']->id,
            'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/settings/seasons/'.$season->id)->assertRedirect();

        $this->assertDatabaseCount('season_periods', 0);
    }

    public function test_a_season_of_another_tenant_is_not_reachable(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreign = $this->createTenantSetup();
        $foreignSeason = $foreign['location']->seasonPeriods()->create([
            'tenant_id' => $foreign['tenant']->id,
            'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/settings/seasons/'.$foreignSeason->id)->assertNotFound();

        $this->assertSame(1, SeasonPeriod::withoutGlobalScopes()->count());
    }

    public function test_the_settings_page_shows_the_season_section(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['location']->seasonPeriods()->create([
            'tenant_id' => $setup['tenant']->id, 'label' => 'Sommersaison',
            'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Buchbare Saison', false)
            ->assertSee('Sommersaison', false)
            ->assertSee('1. April', false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Support\Navigation\NavGroup;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  list<NavGroup>  $groups
     * @return list<array{0: ?string, 1: list<string>}>
     */
    private function shape(array $groups): array
    {
        return array_map(
            fn (NavGroup $group) => [$group->label, array_map(fn (NavItem $item) => $item->key, $group->items)],
            $groups,
        );
    }

    public function test_owner_sees_the_grouped_main_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $groups = Navigation::fromConfig()->main($owner, $setup['tenant'], $setup['location'], 'admin.board');

        $this->assertSame([
            [null, ['dashboard']],
            ['Service', ['board', 'floorplan', 'walkins', 'waitlist']],
            ['Buchungen', ['reservations', 'events', 'refunds']],
            ['Gäste', ['guests', 'marketing']],
            [null, ['reports']],
        ], $this->shape($groups));
        $this->assertTrue($groups[1]->items[0]->active);
        $this->assertFalse($groups[1]->items[1]->active);
    }

    public function test_a_heading_needs_two_visible_items(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');

        $groups = Navigation::fromConfig()->main($host, $setup['tenant'], $setup['location'], null);

        $this->assertSame([
            [null, ['dashboard']],
            ['Service', ['board', 'floorplan', 'walkins', 'waitlist']],
            [null, ['reservations']],
            [null, ['guests']],
        ], $this->shape($groups));
    }

    public function test_salon_gets_its_own_items_and_labels(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $groups = Navigation::fromConfig()->main($owner, $setup['tenant']->refresh(), $setup['location'], null);
        $labels = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                $labels[$item->key] = $item->label;
            }
        }

        $this->assertArrayHasKey('staff-calendar', $labels);
        $this->assertArrayNotHasKey('floorplan', $labels);
        $this->assertSame('Termine', $labels['reservations']);
        $this->assertSame('Kunden', $labels['guests']);
    }

    public function test_mobile_bar_holds_the_four_daily_destinations(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        $items = Navigation::fromConfig()->mobileBar($owner, $setup['tenant'], $setup['location'], null);

        $this->assertSame(['dashboard', 'board', 'reservations', 'guests'], array_map(fn (NavItem $item) => $item->key, $items));
    }

    public function test_settings_are_grouped_and_filtered_by_permission(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = Navigation::fromConfig();

        $shape = $this->shape($nav->settings($owner, $setup['tenant'], $setup['location'], null));

        $this->assertSame(
            ['Betrieb', 'Platz & Zeiten', 'Buchungen', 'Gästekommunikation', 'Zahlungen', 'Verbindungen'],
            array_column($shape, 0),
        );
        $this->assertSame(['opening-hours', 'exceptions', 'rooms', 'capacity'], $shape[1][1]);
        $this->assertSame(route('admin.settings.index').'#allgemein', $nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }

    public function test_settings_entry_follows_the_first_allowed_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $nav = Navigation::fromConfig();

        $this->assertSame(route('admin.settings.index').'#zeiten', $nav->firstSettingsUrl($manager, $setup['tenant'], $setup['location']));
        $this->assertSame([], $nav->settings($staff, $setup['tenant'], $setup['location'], null));
        $this->assertNull($nav->firstSettingsUrl($staff, $setup['tenant'], $setup['location']));
    }

    public function test_settings_routes_are_recognised(): void
    {
        $nav = Navigation::fromConfig();

        $this->assertTrue($nav->isSettingsRoute('admin.users.index'));
        $this->assertTrue($nav->isSettingsRoute('admin.settings.index'));
        $this->assertTrue($nav->isSettingsRoute('admin.billing.show'));
        $this->assertFalse($nav->isSettingsRoute('admin.dashboard'));
        $this->assertFalse($nav->isSettingsRoute('admin.account.show'));
        $this->assertFalse($nav->isSettingsRoute(null));
    }

    public function test_rules_follow_a_different_config(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $nav = new Navigation([
            'mobile_bar_max' => 1,
            'main' => [
                ['label' => 'Gruppe', 'items' => [
                    ['key' => 'a', 'label' => 'A', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
                    ['key' => 'b', 'label' => 'B', 'route' => 'admin.board', 'icon' => 'board', 'only' => 'salon', 'mobile_bar' => true],
                    ['key' => 'c', 'label' => 'C', 'route' => 'admin.reports.index', 'icon' => 'reports', 'can_any' => ['marketing.manage', 'audit.view']],
                ]],
            ],
            'settings' => [],
        ]);

        $this->assertSame([['Gruppe', ['a', 'c']]], $this->shape($nav->main($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([[null, ['a']]], $this->shape($nav->main($host, $setup['tenant'], $setup['location'], null)));
        $this->assertCount(1, $nav->mobileBar($owner, $setup['tenant'], $setup['location'], null));
        $this->assertNull($nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }
}

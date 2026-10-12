<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\User;
use App\Support\Navigation\NavGroup;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    public function test_the_heading_threshold_comes_from_the_settings(): void
    {
        config(['navigation.heading_min_items' => 1]);
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');

        $groups = Navigation::fromConfig()->main($host, $setup['tenant'], $setup['location'], null);

        $this->assertSame([
            [null, ['dashboard']],
            ['Service', ['board', 'floorplan', 'walkins', 'waitlist']],
            ['Buchungen', ['reservations']],
            ['Gäste', ['guests']],
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
        $this->assertSame(route('admin.settings.general.show'), $nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }

    public function test_settings_entry_follows_the_first_allowed_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $nav = Navigation::fromConfig();

        $this->assertSame(route('admin.settings.opening-hours.show'), $nav->firstSettingsUrl($manager, $setup['tenant'], $setup['location']));
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
        $marketing = $this->createMember($setup['tenant'], 'marketing_manager');
        $nav = new Navigation([
            'mobile_bar_max' => 1,
            'main' => [
                ['label' => 'Gruppe', 'items' => [
                    ['key' => 'a', 'label' => 'A', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
                    ['key' => 'b', 'label' => 'B', 'route' => 'admin.board', 'icon' => 'board', 'only' => 'salon', 'mobile_bar' => true],
                    ['key' => 'c', 'label' => 'C', 'route' => 'admin.reports.index', 'icon' => 'reports', 'can_any' => ['marketing.manage', 'audit.view']],
                    ['key' => 'd', 'label' => 'D', 'route' => 'admin.walkins.index', 'icon' => 'walkins', 'mobile_bar' => true],
                ]],
            ],
            'settings' => [],
        ]);

        $this->assertSame([['Gruppe', ['a', 'c', 'd']]], $this->shape($nav->main($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([['Gruppe', ['a', 'd']]], $this->shape($nav->main($host, $setup['tenant'], $setup['location'], null)));
        // The marketing manager holds marketing.manage but not audit.view: one of the two is enough.
        $this->assertSame([['Gruppe', ['a', 'c', 'd']]], $this->shape($nav->main($marketing, $setup['tenant'], $setup['location'], null)));
        $this->assertSame(['a'], array_map(fn (NavItem $item) => $item->key, $nav->mobileBar($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertNull($nav->firstSettingsUrl($owner, $setup['tenant'], $setup['location']));
    }

    public function test_mobile_bar_without_a_limit_returns_every_candidate(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = new Navigation([
            'main' => [
                ['label' => null, 'items' => [
                    ['key' => 'a', 'label' => 'A', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
                    ['key' => 'b', 'label' => 'B', 'route' => 'admin.board', 'icon' => 'board', 'mobile_bar' => true],
                    ['key' => 'c', 'label' => 'C', 'route' => 'admin.walkins.index', 'icon' => 'walkins', 'mobile_bar' => true],
                    ['key' => 'd', 'label' => 'D', 'route' => 'admin.waitlist.index', 'icon' => 'waitlist', 'mobile_bar' => true],
                    ['key' => 'e', 'label' => 'E', 'route' => 'admin.reservations.index', 'icon' => 'reservations', 'mobile_bar' => true],
                ]],
            ],
        ]);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_map(fn (NavItem $item) => $item->key, $nav->mobileBar($owner, $setup['tenant'], $setup['location'], null)));
    }

    public function test_settings_routes_follow_the_config(): void
    {
        $nav = new Navigation(['settings_routes' => ['admin.billing.*'], 'settings' => []]);

        $this->assertTrue($nav->isSettingsRoute('admin.billing.show'));
        $this->assertFalse($nav->isSettingsRoute('admin.settings.index'));
    }

    public function test_legacy_anchors_take_the_first_allowed_target_and_keep_its_fragment(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $nav = new Navigation([
            'legacy_anchors' => [
                'alt' => 'general|opening-hours#woche',
                'allein' => 'general',
                'fremd' => 'gibt-es-nicht|rooms',
                'salon' => 'services|staff',
            ],
            'settings' => (array) config('navigation.settings'),
        ]);

        // Der Inhaber darf die Seite Allgemein: Das erste Ziel gilt, die Adresse endet mit dem Pfad der Seite.
        $this->assertSame([
            'alt' => route('admin.settings.general.show'),
            'allein' => route('admin.settings.general.show'),
            'fremd' => route('admin.settings.rooms.index'),
        ], $nav->legacyAnchors($owner, $setup['tenant'], $setup['location']));

        // Der Standortleiter darf sie nicht: Das zweite Ziel gilt, mit Sprungziel; „allein“ entfällt.
        $this->assertSame([
            'alt' => route('admin.settings.opening-hours.show').'#woche',
            'fremd' => route('admin.settings.rooms.index'),
        ], $nav->legacyAnchors($manager, $setup['tenant'], $setup['location']));

        // Ohne Eintrag in der Konfiguration gibt es keine Marken.
        $this->assertSame([], (new Navigation(['settings' => (array) config('navigation.settings')]))->legacyAnchors($owner, $setup['tenant'], $setup['location']));
    }

    public function test_every_configured_route_exists_and_keys_are_unique(): void
    {
        $keys = [];
        foreach (['main', 'settings'] as $section) {
            foreach ((array) config('navigation.'.$section) as $group) {
                foreach ($group['items'] as $item) {
                    $this->assertTrue(Route::has($item['route']), "Route {$item['route']} of entry {$item['key']} ({$section}) does not exist.");
                    $keys[] = $item['key'];
                }
            }
        }

        $duplicates = array_keys(array_filter(array_count_values($keys), fn (int $count) => $count > 1));
        $this->assertSame([], $duplicates, 'Navigation keys must be unique: '.implode(', ', $duplicates));
    }

    public function test_every_legacy_anchor_points_at_a_configured_settings_page_with_a_section_that_exists(): void
    {
        $pages = collect((array) config('navigation.settings'))->pluck('items')->flatten(1)->keyBy('key');
        $anchors = (array) config('navigation.legacy_anchors');
        $this->assertNotEmpty($anchors);

        foreach ($anchors as $anchor => $targets) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', (string) $anchor, 'Eine alte Marke besteht aus Kleinbuchstaben.');
            foreach (explode('|', (string) $targets) as $target) {
                [$key, $fragment] = array_pad(explode('#', $target, 2), 2, null);
                $this->assertTrue($pages->has($key), "Marke {$anchor}: Die Seite {$key} steht nicht in config('navigation.settings').");
                if ($fragment !== null) {
                    // Das Sprungziel kommt als Stichwort derselben Seite vor ('Suchbegriff' => 'ziel').
                    $this->assertContains($fragment, array_values(array_filter((array) $pages[$key]['keywords'], 'is_string')), "Marke {$anchor}: Das Sprungziel {$fragment} fehlt bei den Suchbegriffen von {$key}.");
                }
            }
        }
    }

    public function test_keywords_can_point_to_a_section(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = new Navigation(['settings' => [
            ['label' => 'Betrieb', 'items' => [
                ['key' => 'a', 'label' => 'A', 'route' => 'admin.locations.index', 'keywords' => ['Adresse', 'Logo' => 'logo', 'Neuer Standort' => 'standort-neu']],
            ]],
        ]]);

        $item = $nav->settings($owner, $setup['tenant'], $setup['location'], null)[0]->items[0];

        $this->assertSame(['Adresse', 'Logo#logo', 'Neuer Standort#standort-neu'], $item->keywords);
    }

    public function test_restaurant_keywords_are_added_for_restaurants_only(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = new Navigation(['settings' => [
            ['label' => 'Betrieb', 'items' => [
                ['key' => 'a', 'label' => 'A', 'route' => 'admin.locations.index',
                    'keywords' => ['Takt' => 'takt'], 'keywords_restaurant' => ['Tischzeit' => 'tischzeiten', 'Bar']],
            ]],
        ]]);

        $restaurant = $nav->settings($owner, $setup['tenant'], $setup['location'], null)[0]->items[0];
        $this->assertSame(['Takt#takt', 'Tischzeit#tischzeiten', 'Bar'], $restaurant->keywords);

        $setup['tenant']->update(['type' => TenantType::Salon]);
        $salon = $nav->settings($owner, $setup['tenant'], $setup['location'], null)[0]->items[0];
        $this->assertSame(['Takt#takt'], $salon->keywords);
    }

    public function test_salons_are_not_offered_search_terms_of_restaurant_sections(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $keywordsOf = function (string $key) use ($owner, $setup): array {
            foreach (Navigation::fromConfig()->settings($owner, $setup['tenant'], $setup['location'], null) as $group) {
                foreach ($group->items as $item) {
                    if ($item->key === $key) {
                        return $item->keywords;
                    }
                }
            }

            return [];
        };

        // Begriffe, deren Ziel nur die Seite eines Restaurants zeigt.
        $restaurantOnly = [
            'capacity' => ['Kapazität#kapazitaet', 'Gäste gleichzeitig#kapazitaet', 'Tischzeit#tischzeiten', 'Besuchsdauer#takt'],
            'booking-rules' => ['Personen online#gruppen', 'Große Gruppen#gruppen', 'Walk-ins#warteliste'],
            'booking-form' => ['Pflichtfeld#felder', 'Telefon#felder', 'Anlass#felder', 'Allergien#felder'],
            'booking-page' => ['Öffentlicher Tischplan#buchungsseite'],
        ];
        // Begriffe, die zu beiden Betriebsarten passen.
        $both = [
            'capacity' => ['Takt#takt', 'Slot-Intervall#takt', 'Puffer#takt', 'Lückenoptimierung#takt'],
            'booking-rules' => ['Vorlauf#zeitraum', 'Stornofrist#fristen', 'Warteliste#warteliste'],
            'booking-form' => ['Formularfelder#felder'],
            'booking-page' => ['Buchungsseite#buchungsseite', 'Konfetti#buchungsseite', 'Direktlink#direktlink'],
        ];

        foreach ([$restaurantOnly, $both] as $expected) {
            foreach ($expected as $key => $terms) {
                $this->assertSame([], array_values(array_diff($terms, $keywordsOf($key))), "{$key}: Restaurants sehen alle Begriffe");
            }
        }

        $setup['tenant']->update(['type' => TenantType::Salon]);

        foreach ($restaurantOnly as $key => $terms) {
            $this->assertSame([], array_values(array_intersect($terms, $keywordsOf($key))), "{$key}: Salons sehen die Begriffe der Restaurantseiten nicht");
        }
        foreach ($both as $key => $terms) {
            $this->assertSame([], array_values(array_diff($terms, $keywordsOf($key))), "{$key}: Salons behalten die gemeinsamen Begriffe");
        }
    }

    public function test_roles_limit_an_entry_to_members_with_that_role(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $support = User::factory()->create(['saas_role' => 'support_admin']);
        $nav = new Navigation(['settings' => [
            ['label' => 'Betrieb', 'items' => [
                ['key' => 'a', 'label' => 'A', 'route' => 'admin.locations.index'],
                ['key' => 'b', 'label' => 'B', 'route' => 'admin.users.index', 'roles' => ['tenant_owner']],
            ]],
        ]]);

        $this->assertSame([['Betrieb', ['a', 'b']]], $this->shape($nav->settings($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([['Betrieb', ['a']]], $this->shape($nav->settings($admin, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([['Betrieb', ['a']]], $this->shape($nav->settings($support, $setup['tenant'], $setup['location'], null)));
    }

    public function test_the_data_entry_is_reserved_for_owners(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $nav = Navigation::fromConfig();

        $keys = fn (User $user) => array_merge(...array_map(
            fn (NavGroup $group) => array_map(fn (NavItem $item) => $item->key, $group->items),
            $nav->settings($user, $setup['tenant'], $setup['location'], null),
        ));

        $this->assertContains('data', $keys($owner));
        $this->assertNotContains('data', $keys($admin));
    }

    public function test_location_access_is_checked_once_per_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager', false);
        DB::table('location_user')->insert([
            'location_id' => $setup['location']->id,
            'user_id' => $manager->id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nav = Navigation::fromConfig();

        DB::enableQueryLog();
        $main = $nav->main($manager, $setup['tenant'], $setup['location'], null);
        $nav->settings($manager, $setup['tenant'], $setup['location'], null);
        $nav->mobileBar($manager, $setup['tenant'], $setup['location'], null);
        $url = $nav->firstSettingsUrl($manager, $setup['tenant'], $setup['location']);
        $queries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'location_user'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(['board', 'floorplan', 'walkins', 'waitlist'], array_map(fn (NavItem $item) => $item->key, $main[1]->items));
        $this->assertSame(route('admin.settings.opening-hours.show'), $url);
    }

    public function test_the_settings_menu_shares_the_layouts_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager', false);
        DB::table('location_user')->insert([
            'location_id' => $setup['location']->id,
            'user_id' => $manager->id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clearTenantContext();
        $count = function (string $url) use ($manager): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($manager)->get($url)->assertOk();
            $queries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'location_user'));
            DB::disableQueryLog();

            return count($queries);
        };

        // Das Menü baut keine zweite Navigation: nicht mehr Standortabfragen als jede andere Seite.
        $this->assertSame($count('/admin'), $count('/admin/settings/menu'));
    }

    public function test_a_member_without_access_to_the_location_sees_only_unrestricted_entries(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager', false);

        $groups = Navigation::fromConfig()->main($manager, $setup['tenant'], $setup['location'], null);

        $this->assertSame([[null, ['dashboard']]], $this->shape($groups));
    }

    public function test_platform_support_sees_every_settings_page_except_data(): void
    {
        $setup = $this->createTenantSetup();
        $support = User::factory()->create(['saas_role' => 'support_admin']);

        $keys = array_merge(...array_column($this->shape(
            Navigation::fromConfig()->settings($support, $setup['tenant'], $setup['location'], null),
        ), 1));

        $this->assertContains('general', $keys);
        $this->assertContains('capacity', $keys);
        $this->assertContains('tags', $keys);
        $this->assertNotContains('data', $keys);
    }

    public function test_platform_read_access_sees_only_pages_for_viewing(): void
    {
        $setup = $this->createTenantSetup();
        $reader = User::factory()->create(['saas_role' => 'readonly_admin']);
        $nav = Navigation::fromConfig();

        $this->assertSame([[null, ['audit']]], $this->shape($nav->settings($reader, $setup['tenant'], $setup['location'], null)));
        $this->assertSame(
            ['dashboard', 'board', 'floorplan', 'reservations', 'guests', 'reports'],
            array_merge(...array_column($this->shape($nav->main($reader, $setup['tenant'], $setup['location'], null)), 1)),
        );
    }
}

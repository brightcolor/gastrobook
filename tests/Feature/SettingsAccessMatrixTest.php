<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Navigation\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsAccessMatrixTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Routen ohne permission-Middleware, je mit Grund. */
    private const OPEN = [
        'admin.settings.index' => 'leitet auf die erste erlaubte Seite',
        'admin.settings.menu' => 'zeigt nur erlaubte Seiten, sonst 403',
        'admin.settings.data.show' => 'prüft im Controller auf Inhaber',
    ];

    /** Seiten, deren eigenes Recht tables.manage ist. */
    private const TABLES_ONLY = ['admin.settings.rooms.', 'admin.settings.tables.', 'admin.settings.combinations.', 'admin.settings.tags.'];

    public function test_every_settings_route_carries_a_permission(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! Str::startsWith($name, 'admin.settings.') || array_key_exists($name, self::OPEN)) {
                continue;
            }
            $permissions = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && Str::startsWith($m, 'permission:'));
            $this->assertNotEmpty($permissions, "Route {$name} hat keine permission-Middleware.");
            if (array_values($permissions) === ['permission:tables.manage']) {
                $this->assertTrue(Str::startsWith($name, self::TABLES_ONLY), "Route {$name} hängt nur an tables.manage; die Seite braucht ihr eigenes Recht.");
            }
        }
    }

    public function test_each_page_opens_exactly_for_the_roles_that_see_it_in_the_navigation(): void
    {
        foreach ([false, true] as $salon) {
            $setup = $this->createTenantSetup();
            if ($salon) {
                $setup['tenant']->update(['type' => 'salon']);
            }

            foreach (array_keys((array) config('permissions.roles')) as $role) {
                $user = $this->createMember($setup['tenant'], $role);
                $this->clearTenantContext();
                $this->actingAs($user);
                $visible = collect(Navigation::fromConfig()->settings($user, $setup['tenant']->fresh(), $setup['location'], null))
                    ->flatMap(fn ($group) => $group->items)->pluck('key')->all();

                foreach ((array) config('navigation.settings') as $group) {
                    foreach ($group['items'] as $item) {
                        if (($item['only'] ?? null) !== null && $item['only'] !== ($salon ? 'salon' : 'restaurant')) {
                            continue;
                        }
                        $status = $this->get(route($item['route']))->status();
                        $expected = in_array($item['key'], $visible, true) ? 200 : 403;
                        $this->assertSame($expected, $status, "{$role} → {$item['key']} (".($salon ? 'Salon' : 'Restaurant').')');
                    }
                }
            }
        }
    }
}

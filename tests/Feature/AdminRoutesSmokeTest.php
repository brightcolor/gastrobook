<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Hits every parameter-free admin GET route with a full-permission owner and
 * asserts the response is not a server error (status < 500). This catches
 * render/exception regressions the moment they land — e.g. a broken Blade
 * template or a controller that throws — without being brittle about the exact
 * 200/302/403 outcome. New routes are picked up automatically.
 */
class AdminRoutesSmokeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Routes that must be skipped: SSE streams hang the test runner, and the
     * SEPA redirect flow reaches out to GoCardless (external integration, not a
     * plain page render).
     */
    private const SKIP = [
        'admin.board.stream',
        'admin.billing.directdebit.setup',
        'admin.billing.directdebit.complete',
    ];

    public function test_all_admin_get_routes_render_without_server_error(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $tested = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $name = $route->getName();
            $uri = $route->uri();

            // parameter-free admin routes only
            if (! str_starts_with($uri, 'admin')) {
                continue;
            }
            if (str_contains($uri, '{')) {
                continue;
            }
            if ($name !== null && in_array($name, self::SKIP, true)) {
                continue;
            }

            $response = $this->actingAs($owner)->get('/'.$uri);

            $tested++;
            if ($response->getStatusCode() >= 500) {
                $failures[] = $uri.' → '.$response->getStatusCode();
            }
        }

        $this->assertGreaterThan(20, $tested, 'Es sollten viele Admin-Routen geprüft werden.');
        $this->assertSame([], $failures, "Admin-Routen mit Serverfehler:\n".implode("\n", $failures));
    }
}

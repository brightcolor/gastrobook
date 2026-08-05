<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The spec is a promise to integrators. These tests keep it honest: it is
 * served, and it does not quietly drift away from the routes and enums.
 */
class OpenApiSpecTest extends TestCase
{
    public function test_the_spec_is_served_without_a_token(): void
    {
        $response = $this->get('/api/v1/openapi.yaml')->assertOk();

        $this->assertStringContainsString('yaml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('openapi: 3.1.0', (string) file_get_contents(resource_path('api/openapi.yaml')));
    }

    public function test_every_api_route_appears_in_the_spec(): void
    {
        $spec = (string) file_get_contents(resource_path('api/openapi.yaml'));

        $paths = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/v1/'))
            ->reject(fn (string $uri) => str_ends_with($uri, 'openapi.yaml'))
            // api/v1/reservations/{code}/cancel → /reservations/{code}/cancel
            ->map(fn (string $uri) => '/'.substr($uri, strlen('api/v1/')))
            // The spec documents these by id; the routes bind the model.
            ->map(fn (string $uri) => str_replace(['{guest}', '{endpoint}'], ['{id}', '{id}'], $uri))
            ->unique();

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertStringContainsString(
                "\n  {$path}:",
                $spec,
                "Der Endpunkt {$path} fehlt in resources/api/openapi.yaml."
            );
        }
    }

    public function test_the_documented_statuses_match_the_enum(): void
    {
        $spec = (string) file_get_contents(resource_path('api/openapi.yaml'));

        foreach (ReservationStatus::cases() as $status) {
            $this->assertStringContainsString(
                '- '.$status->value,
                $spec,
                "Status {$status->value} fehlt in der OpenAPI-Beschreibung."
            );
        }
    }
}

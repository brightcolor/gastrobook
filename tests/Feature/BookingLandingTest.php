<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingLandingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_single_location_shows_booking_page_at_short_url(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug)
            ->assertOk()
            ->assertSee($setup['location']->name)
            ->assertDontSee('Standort wählen');
    }

    public function test_single_location_store_works_from_short_url(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        // Validation should fail (empty form), but NOT 404 – route exists
        $this->post('/book/'.$setup['tenant']->slug, ['_token' => csrf_token()])
            ->assertSessionHasErrors();
    }

    public function test_multiple_locations_show_a_chooser(): void
    {
        $setup = $this->createTenantSetup();
        $second = Location::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'name' => 'Zweitstandort',
            'is_active' => true,
            'online_booking_enabled' => true,
        ]);
        $this->clearTenantContext();

        $response = $this->get('/book/'.$setup['tenant']->slug)->assertOk();

        $response->assertSee('Standort wählen');
        $response->assertSee($setup['location']->name);
        $response->assertSee('Zweitstandort');
        // Links point to the per-location URL (slug appended)
        $response->assertSee('/book/'.$setup['tenant']->slug.'/'.$second->slug, false);
    }

    public function test_landing_404_when_no_bookable_location(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['online_booking_enabled' => false]);
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug)->assertNotFound();
    }
}

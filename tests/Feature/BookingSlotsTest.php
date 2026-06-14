<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingSlotsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function url(array $setup, string $date, int $party): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/slots?date='.$date.'&party_size='.$party;
    }

    public function test_oversized_party_gets_contact_hint_not_waitlist(): void
    {
        // Largest table seats 4
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['location']->update(['phone' => '+49 30 123456']);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->getJson($this->url($setup, $date, 6))->assertOk();

        $response->assertJson(['oversized' => true, 'max_party' => 4]);
        // No waitlist suggestion when the party simply cannot be seated
        $this->assertArrayNotHasKey('waitlist_available', $response->json());
        $this->assertSame([], $response->json('slots'));
    }

    public function test_fitting_party_gets_slots_not_oversized(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->getJson($this->url($setup, $date, 2))->assertOk();

        $this->assertArrayNotHasKey('oversized', $response->json());
        $this->assertNotEmpty($response->json('slots'));
    }
}

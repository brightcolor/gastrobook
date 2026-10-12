<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Meldungen beim Anlegen einer Reservierung im Reservierungsbuch: Jede nennt
 * die Ursache und den nächsten Schritt.
 */
class ReservationBookMessagesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_overbooking_without_the_right_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $tomorrow = CarbonImmutable::now($setup['location']->timezone)->addDay();
        $this->clearTenantContext();

        $payload = ['date' => $tomorrow->toDateString(), 'time' => '19:00', 'party_size' => 2, 'name' => 'Frau Albrecht', 'force' => '1'];

        $response = $this->actingAs($host)->postJson('/admin/reservations', $payload)->assertForbidden();

        $this->assertSame(
            'Überbuchung erfordert eine erweiterte Berechtigung. Bitte das Häkchen „Verfügbarkeitsprüfung übergehen“ weglassen oder die Betriebsleitung bitten, die Reservierung anzulegen.',
            $response->json('message'),
        );
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Die Fehlerseite zeigt denselben Satz als Ursache.
        $this->actingAs($host)->post('/admin/reservations', $payload)
            ->assertForbidden()
            ->assertSee('Bitte das Häkchen „Verfügbarkeitsprüfung übergehen“ weglassen', false);
    }
}

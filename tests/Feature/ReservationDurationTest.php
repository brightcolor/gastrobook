<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein Formular schickt jedes Feld als Text. Die Dauer kam so als String „90"
 * bis in `CarbonImmutable::addMinutes()` und brach dort mit einem TypeError ab
 * (HTTP 500) – die „integer"-Regel prüft nur, sie wandelt nicht um. Das Anlegen
 * einer Reservierung muss die Dauer als Zahl behandeln.
 */
class ReservationDurationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_custom_duration_sent_as_text_creates_the_reservation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $tag = CarbonImmutable::now($tz)->addDays(2);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations', [
            'date' => $tag->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Frau Albrecht',
            // Wie aus dem Formular: Text, nicht Zahl.
            'duration_minutes' => '90',
            'source' => 'manual',
        ])->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->sole();
        $this->assertSame(90, (int) $reservation->start_at->diffInMinutes($reservation->end_at));
    }
}

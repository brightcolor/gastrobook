<?php

namespace Tests\Feature;

use App\Models\GuestMailReply;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationRepliesViewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_reservation_show_lists_guest_reply(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = Reservation::factory()->create(['location_id' => $setup['location']->id]);
        $this->clearTenantContext();

        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id, 'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id, 'guest_id' => $reservation->guest_id,
            'postal_message_id' => 'v-1', 'from_email' => 'gast@example.test',
            'to_recipient' => 'x', 'subject' => 'Bitte 20 Uhr', 'body_text' => 'Geht das?',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.reservations.show', $reservation))
            ->assertOk()->assertSee('Bitte 20 Uhr');
    }
}

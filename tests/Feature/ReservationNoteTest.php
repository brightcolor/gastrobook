<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Notes on a reservation were displayed from the very first version – the card
 * just stayed empty forever, because nothing could write one.
 */
class ReservationNoteTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function reservation(array $setup): Reservation
    {
        return Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
        ]);
    }

    public function test_a_note_can_be_written_and_shows_up_on_the_booking(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/notes', [
            'body' => 'Ruft nochmal an wegen Kinderstuhl',
        ])->assertRedirect();

        $note = ReservationNote::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($reservation->id, $note->reservation_id);
        $this->assertSame($admin->id, $note->user_id);

        $this->actingAs($admin)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('Ruft nochmal an wegen Kinderstuhl');
    }

    public function test_an_empty_note_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/notes', ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ReservationNote::withoutGlobalScopes()->count());
    }

    public function test_read_only_users_cannot_write_notes(): void
    {
        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($readonly)->post('/admin/reservations/'.$reservation->id.'/notes', [
            'body' => 'Darf ich nicht',
        ])->assertForbidden();

        $this->assertSame(0, ReservationNote::withoutGlobalScopes()->count());
    }

    public function test_a_booking_of_another_business_is_out_of_reach(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $foreign = $this->reservation($other);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$foreign->id.'/notes', [
            'body' => 'Fremd',
        ])->assertNotFound();

        $this->assertSame(0, ReservationNote::withoutGlobalScopes()->count());
    }
}

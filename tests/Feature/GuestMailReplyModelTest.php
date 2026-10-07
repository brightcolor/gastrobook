<?php

namespace Tests\Feature;

use App\Models\GuestMailReply;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestMailReplyModelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function reservation(): Reservation
    {
        $setup = $this->createTenantSetup();

        return Reservation::factory()->create(['location_id' => $setup['location']->id]);
    }

    public function test_reply_belongs_to_reservation_and_dedupes(): void
    {
        $reservation = $this->reservation();

        $reply = GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id,
            'postal_message_id' => 'msg-1',
            'from_email' => 'gast@example.test',
            'to_recipient' => 'slug+R-X.y@antwort.swayy.de',
            'subject' => 'Re: Buchung',
            'body_text' => 'Danke!',
            'has_attachments' => false,
            'match_status' => 'matched',
            'forward_status' => 'queued',
            'received_at' => now(),
        ]);

        $this->assertTrue($reservation->mailReplies()->whereKey($reply->id)->exists());

        $this->expectException(QueryException::class);
        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'postal_message_id' => 'msg-1', // Duplikat
            'to_recipient' => 'x', 'match_status' => 'unmatched', 'forward_status' => 'skipped', 'received_at' => now(),
        ]);
    }

    public function test_reply_is_deleted_with_reservation(): void
    {
        $reservation = $this->reservation();
        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id, 'reservation_id' => $reservation->id,
            'postal_message_id' => 'msg-2', 'to_recipient' => 'x',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);
        $reservation->forceDelete();
        $this->assertSame(0, GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'msg-2')->count());
    }
}

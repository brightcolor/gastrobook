<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestMailReply;
use App\Models\Reservation;
use App\Services\GuestPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestMailReplyPrivacyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_anonymize_scrubs_reply_pii(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'email' => 'gast@example.test']);
        $reservation = Reservation::factory()->create(['location_id' => $setup['location']->id, 'guest_id' => $guest->id]);

        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'guest_id' => $guest->id, 'postal_message_id' => 'p-1',
            'from_email' => 'gast@example.test', 'from_name' => 'Max Gast',
            'to_recipient' => 'x', 'subject' => 'Geheim', 'body_text' => 'Privat',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);

        app(GuestPrivacyService::class)->anonymize($guest);

        $reply = GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'p-1')->first();
        $this->assertNull($reply->from_email);
        $this->assertNull($reply->from_name);
        $this->assertNull($reply->subject);
        $this->assertNull($reply->body_text);
    }
}

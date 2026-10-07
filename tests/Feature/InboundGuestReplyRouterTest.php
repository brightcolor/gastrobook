<?php

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use App\Models\GuestMailReply;
use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;
use App\Services\Mail\InboundGuestReplyRouter;
use App\Services\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class InboundGuestReplyRouterTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
    }

    private function reservation(): Reservation
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['email' => 'betrieb@example.test']);

        return Reservation::factory()->create(['location_id' => $setup['location']->id])->load('tenant');
    }

    private function message(bool $auto = false): InboundMessage
    {
        $raw = "From: Gast <gast@example.test>\r\nSubject: Re: Buchung\r\nMessage-ID: <m1@example.test>\r\n"
            .($auto ? "Auto-Submitted: auto-replied\r\n" : '')."\r\nText hier.";

        return InboundMessage::fromRaw($raw);
    }

    public function test_matched_reply_is_stored_and_forwarded(): void
    {
        Mail::fake();
        $reservation = $this->reservation();
        $addr = GuestReplyAddress::forReservation($reservation);

        $reply = app(InboundGuestReplyRouter::class)->route($addr, 'gast@example.test', 'pm-1', $this->message());

        $this->assertSame('matched', $reply->match_status);
        $this->assertSame($reservation->id, $reply->reservation_id);
        Mail::assertQueued(ForwardedGuestReplyMail::class, fn ($m) => $m->guestReplyTo === 'gast@example.test');
    }

    public function test_slug_only_is_forwarded_without_booking(): void
    {
        Mail::fake();
        $reservation = $this->reservation();
        $slug = $reservation->tenant->slug;

        $reply = app(InboundGuestReplyRouter::class)
            ->route($slug.'+R-WRONG9.deadbeef@antwort.swayy.de', 'gast@example.test', 'pm-2', $this->message());

        $this->assertSame('slug_only', $reply->match_status);
        $this->assertNull($reply->reservation_id);
        Mail::assertQueued(ForwardedGuestReplyMail::class);
    }

    public function test_unknown_slug_is_dropped(): void
    {
        Mail::fake();
        $reply = app(InboundGuestReplyRouter::class)
            ->route('nobody+R-X.y@antwort.swayy.de', 'gast@example.test', 'pm-3', $this->message());

        $this->assertSame('unmatched', $reply->match_status);
        Mail::assertNothingQueued();
    }

    public function test_auto_reply_is_not_forwarded(): void
    {
        Mail::fake();
        $reservation = $this->reservation();
        $addr = GuestReplyAddress::forReservation($reservation);

        $reply = app(InboundGuestReplyRouter::class)->route($addr, 'gast@example.test', 'pm-4', $this->message(auto: true));

        $this->assertSame('skipped', $reply->forward_status);
        Mail::assertNothingQueued();
    }

    public function test_sender_over_rate_limit_is_not_forwarded(): void
    {
        Mail::fake();
        config(['swayy.guest_mail_relay.rate_per_sender' => 1]);
        $reservation = $this->reservation();
        $addr = GuestReplyAddress::forReservation($reservation);
        $router = app(InboundGuestReplyRouter::class);

        $router->route($addr, 'gast@example.test', 'pm-6a', $this->message());
        $second = $router->route($addr, 'gast@example.test', 'pm-6b', $this->message());

        $this->assertSame('skipped', $second->forward_status);
        Mail::assertQueued(ForwardedGuestReplyMail::class, 1);
    }

    public function test_replay_is_idempotent(): void
    {
        Mail::fake();
        $reservation = $this->reservation();
        $addr = GuestReplyAddress::forReservation($reservation);
        $router = app(InboundGuestReplyRouter::class);

        $first = $router->route($addr, 'gast@example.test', 'pm-5', $this->message());
        $second = $router->route($addr, 'gast@example.test', 'pm-5', $this->message());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'pm-5')->count());
    }
}

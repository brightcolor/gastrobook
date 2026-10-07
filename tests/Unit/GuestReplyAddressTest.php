<?php

namespace Tests\Unit;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Mail\GuestReplyAddress;
use Tests\TestCase;

class GuestReplyAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
        config(['swayy.guest_mail_relay.hmac_length' => 8]);
    }

    public function test_build_and_parse_roundtrip(): void
    {
        $r = new Reservation(['code' => 'R-7KQ2M9']);
        $r->tenant_id = 42;
        $r->setRelation('tenant', new Tenant(['slug' => 'baeckerei-mueller']));

        $addr = GuestReplyAddress::forReservation($r);
        $this->assertStringContainsString('baeckerei-mueller+R-7KQ2M9.', $addr);
        $this->assertStringEndsWith('@antwort.swayy.de', $addr);

        $parsed = GuestReplyAddress::parse($addr);
        $this->assertSame('baeckerei-mueller', $parsed['slug']);
        $this->assertSame('R-7KQ2M9', $parsed['code']); // enthält selbst ein -
        $this->assertSame(GuestReplyAddress::hmac(42, 'R-7KQ2M9'), $parsed['hmac']);
    }

    public function test_parse_rejects_missing_tag(): void
    {
        $this->assertNull(GuestReplyAddress::parse('baeckerei-mueller@antwort.swayy.de'));
        $this->assertNull(GuestReplyAddress::parse('not-an-address'));
    }

    public function test_hmac_changes_with_length_setting(): void
    {
        $this->assertSame(8, strlen(GuestReplyAddress::hmac(1, 'R-AAAAAA')));
        config(['swayy.guest_mail_relay.hmac_length' => 12]);
        $this->assertSame(12, strlen(GuestReplyAddress::hmac(1, 'R-AAAAAA')));
    }
}

<?php

namespace Tests\Unit;

use App\Support\EnvSetting;
use Tests\TestCase;

class GuestMailRelayConfigTest extends TestCase
{
    public function test_defaults_are_present(): void
    {
        $this->assertSame('', config('swayy.guest_mail_relay.domain'));
        $this->assertSame(8, config('swayy.guest_mail_relay.hmac_length'));
        $this->assertSame('.', config('swayy.guest_mail_relay.separator_hmac'));
        $this->assertSame('sha256', config('swayy.guest_mail_relay.inbound_signature_algo'));
        $this->assertContains('Auto-Submitted', config('swayy.guest_mail_relay.auto_mail_headers'));
    }

    public function test_hmac_length_respects_env_bounds(): void
    {
        $var = 'SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH';
        $_SERVER[$var] = '4'; // unter min 6
        $_ENV[$var] = '4';

        try {
            $this->assertSame(6, EnvSetting::integer($var, default: 8, min: 6, max: 32));
        } finally {
            unset($_SERVER[$var], $_ENV[$var]);
        }
    }
}

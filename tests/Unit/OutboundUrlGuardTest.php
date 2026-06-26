<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    public function test_rejects_loopback_and_private_and_metadata_ips(): void
    {
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://127.0.0.1/hook'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://10.0.0.5/hook'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://192.168.1.10/hook'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://172.16.0.1/hook'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://[::1]/hook'));
    }

    public function test_rejects_non_https_and_credentialed_urls(): void
    {
        $this->assertFalse(OutboundUrlGuard::isAllowed('http://93.184.216.34/hook')); // not https
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://user:pass@93.184.216.34/hook'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('not-a-url'));
    }

    public function test_allows_public_ip_literal(): void
    {
        // Public IPv4 literal – no DNS lookup needed.
        $this->assertTrue(OutboundUrlGuard::isAllowed('https://93.184.216.34/hook'));
    }
}

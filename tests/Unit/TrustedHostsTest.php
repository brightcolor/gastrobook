<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TrustedHosts;
use PHPUnit\Framework\TestCase;

/**
 * Am 08.08.2026 hat genau diese Ableitung die Live-Instanz auf 400 gelegt:
 * APP_URL stand auf http://localhost:8081, die Seite lief aber unter einer
 * echten Domain. Der Fall unten haelt das fest.
 */
class TrustedHostsTest extends TestCase
{
    public function test_a_real_domain_is_bound_including_its_subdomains(): void
    {
        $muster = TrustedHosts::patterns('https://swayy.de');

        $this->assertCount(1, $muster);
        $this->assertSame(1, preg_match('{'.$muster[0].'}i', 'swayy.de'));
        $this->assertSame(1, preg_match('{'.$muster[0].'}i', 'kunde.swayy.de'));
        $this->assertSame(0, preg_match('{'.$muster[0].'}i', 'swayy.de.angreifer.test'));
        $this->assertSame(0, preg_match('{'.$muster[0].'}i', 'fremd.test'));
    }

    /**
     * Der eigentliche Ausfallgrund: Steht in APP_URL nur localhost, darf
     * ueberhaupt keine Einschraenkung entstehen – sonst sperrt die App jeden
     * echten Aufruf aus.
     */
    public function test_a_local_app_url_restricts_nothing(): void
    {
        $this->assertSame([], TrustedHosts::patterns('http://localhost:8081'));
        $this->assertSame([], TrustedHosts::patterns('http://127.0.0.1'));
        $this->assertSame([], TrustedHosts::patterns(''));
        $this->assertSame([], TrustedHosts::patterns('kein-url'));
    }

    public function test_an_ip_address_has_no_subdomains(): void
    {
        $muster = TrustedHosts::patterns('http://203.0.113.10');

        $this->assertSame(1, preg_match('{'.$muster[0].'}i', '203.0.113.10'));
        $this->assertSame(0, preg_match('{'.$muster[0].'}i', 'sub.203.0.113.10'));
    }
}

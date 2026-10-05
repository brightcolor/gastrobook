<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Laeuft mit der Anwendung: Die Freigabe interner Netze kommt aus der
 * Konfiguration.
 */
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

    /**
     * Die Meldung haengt am Grund. Wer eine Adresse eintraegt, soll erfahren,
     * was an ihr nicht passt.
     *
     * @return array<string, array{string, string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'keine Adresse' => ['not-a-url', OutboundUrlBlocked::INVALID],
            'Prozentzeichen im Host' => ['https://news%2eexample.test/api', OutboundUrlBlocked::INVALID],
            'Leerzeichen im Host' => ['https://news example.test/api', OutboundUrlBlocked::INVALID],
            'Umlaut im Host' => ['https://nachrichten-müller.test/api', OutboundUrlBlocked::INVALID],
            'Punkt am Ende des Namens' => ['https://news.example.test./api', OutboundUrlBlocked::INVALID],
            'http' => ['http://news.example.test/api', OutboundUrlBlocked::SCHEME],
            'Zugangsdaten' => ['https://user:pw@news.example.test/api', OutboundUrlBlocked::CREDENTIALS],
            'nicht aufloesbar' => ['https://unbekannt.example.test/api', OutboundUrlBlocked::UNRESOLVABLE],
            'Name auf internes Netz' => ['https://intern.example.test/api', OutboundUrlBlocked::INTERNAL],
            'Loopback als IP' => ['https://127.0.0.1/api', OutboundUrlBlocked::INTERNAL],
            'IPv6-Loopback' => ['https://[::1]/api', OutboundUrlBlocked::INTERNAL],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_check_names_the_reason(string $url, string $reason): void
    {
        $this->resolve(['intern.example.test' => ['10.0.0.8']]);

        try {
            OutboundUrlGuard::check($url);
            $this->fail("{$url} haette abgelehnt werden muessen.");
        } catch (OutboundUrlBlocked $e) {
            $this->assertSame($reason, $e->reason, $url);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * Ein Name mit einer oeffentlichen und einer internen Adresse ist
     * abgelehnt: curl wuerde sich eine der beiden aussuchen.
     */
    public function test_a_name_is_refused_when_any_of_its_addresses_is_internal(): void
    {
        $this->resolve(['gemischt.example.test' => ['93.184.216.34', '10.0.0.8']]);

        $this->assertNull(OutboundUrlGuard::publicIpsFor('https://gemischt.example.test/api'));
    }

    /**
     * Als oeffentlich gilt nur, was global geroutet wird. Die Adressbereiche
     * fuer Dokumentation und Messungen sind es nicht.
     */
    public function test_addresses_outside_the_global_range_are_refused(): void
    {
        foreach (['192.0.2.10', '198.51.100.7', '203.0.113.5', '198.18.0.1'] as $ip) {
            $this->assertFalse(OutboundUrlGuard::isAllowed("https://{$ip}/api"), $ip);
        }

        $this->resolve(['doku.example.test' => ['2001:db8::1']]);
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://doku.example.test/api'));
    }

    public function test_public_ipv6_literals_are_checked_as_addresses(): void
    {
        $this->assertSame(['2606:4700:4700::1111'], OutboundUrlGuard::check('https://[2606:4700:4700::1111]/api'));

        // Eine IP im Host braucht keinen Festnagel-Ausdruck.
        $this->assertSame([], OutboundUrlGuard::resolveOption('https://[2606:4700:4700::1111]/api', ['2606:4700:4700::1111']));
    }

    /**
     * Die Freigabe gilt genau fuer die eingetragenen Netze. Loopback und
     * andere interne Netze bleiben gesperrt.
     */
    public function test_an_allowed_network_opens_exactly_that_network(): void
    {
        config(['swayy.outbound.allowed_networks' => ['10.20.0.0/16', 'fd00:1::/32']]);
        $this->resolve(['mailwizz.intern.test' => ['10.20.0.9']]);

        $this->assertSame(['10.20.4.5'], OutboundUrlGuard::check('https://10.20.4.5/api'));
        $this->assertSame(['10.20.0.9'], OutboundUrlGuard::check('https://mailwizz.intern.test/api'));
        $this->assertTrue(OutboundUrlGuard::isAllowed('https://[fd00:1::5]/api'));

        $this->assertFalse(OutboundUrlGuard::isAllowed('https://10.21.0.1/api'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://127.0.0.1/api'));
        $this->assertFalse(OutboundUrlGuard::isAllowed('https://169.254.169.254/latest/meta-data/'));
    }

    public function test_without_allowed_networks_internal_targets_stay_closed(): void
    {
        config(['swayy.outbound.allowed_networks' => []]);

        $this->assertFalse(OutboundUrlGuard::isAllowed('https://10.20.4.5/api'));
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private function resolve(array $map): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host) => $map[$host] ?? []);
    }
}

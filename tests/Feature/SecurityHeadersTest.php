<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Klickschutz und Einbettung.
 *
 * Beides hing bisher am vorgelagerten nginx von swayy.de - pauschal, und damit
 * doppelt falsch: Eine Selbstinstallation nach README hatte gar keinen Schutz,
 * und die Einbett-Widgets waren bei jedem Kunden tot, weil derselbe Header das
 * iframe verbot, das sie aufbauen.
 */
class SecurityHeadersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_admin_area_refuses_to_be_framed(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $antwort = $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertSame("frame-ancestors 'none'", $antwort->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $antwort->headers->get('X-Frame-Options'));
    }

    public function test_the_login_page_refuses_to_be_framed(): void
    {
        $antwort = $this->get('/login')->assertOk();

        $this->assertSame("frame-ancestors 'none'", $antwort->headers->get('Content-Security-Policy'));
    }

    /**
     * Und die Buchungsseite muss sich einbetten lassen - das ist ihr Zweck.
     * Ein Widget, das der eigene Header aussperrt, ist kein Widget.
     */
    public function test_the_booking_page_and_the_widgets_may_be_framed(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $pfade = [
            '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug,
            '/embed/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'.js',
            '/widget/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/popup.js',
        ];

        foreach ($pfade as $pfad) {
            $antwort = $this->get($pfad)->assertOk();

            $this->assertSame('frame-ancestors *', $antwort->headers->get('Content-Security-Policy'), $pfad);
            $this->assertNull($antwort->headers->get('X-Frame-Options'), $pfad.' sperrt sich selbst aus.');
        }
    }

    /**
     * Der Verwaltungslink traegt den Zugriffstoken in der Adresse - er gehoert
     * NICHT in ein fremdes iframe, auch wenn er oeffentlich erreichbar ist.
     */
    public function test_the_management_link_is_not_embeddable(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $antwort = $this->get('/konto/'.$setup['tenant']->slug)->assertOk();

        $this->assertSame("frame-ancestors 'none'", $antwort->headers->get('Content-Security-Policy'));
    }

    public function test_every_response_carries_the_basic_headers(): void
    {
        $antwort = $this->get('/')->assertOk();

        $this->assertSame('nosniff', $antwort->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $antwort->headers->get('Referrer-Policy'));
    }

    /**
     * Der Wächter gegen DNS-Rebinding liefert jetzt die geprueften Adressen
     * mit, damit der Aufruf sie festnageln kann. Ohne diesen Ausdruck loest
     * curl den Namen selbst noch einmal auf.
     */
    public function test_the_outbound_guard_pins_the_addresses_it_checked(): void
    {
        $this->assertSame(
            ['beispiel.test:443:203.0.113.5,203.0.113.6'],
            OutboundUrlGuard::resolveOption('https://beispiel.test/hook', ['203.0.113.5', '203.0.113.6'])
        );

        // Eigener Port wird mitgenommen.
        $this->assertSame(
            ['beispiel.test:8443:203.0.113.5'],
            OutboundUrlGuard::resolveOption('https://beispiel.test:8443/hook', ['203.0.113.5'])
        );

        // Steht im Host schon eine IP, gibt es nichts festzunageln.
        $this->assertSame([], OutboundUrlGuard::resolveOption('https://203.0.113.5/hook', ['203.0.113.5']));
    }

    /**
     * Und die interne Adresse bleibt gesperrt - in beiden Formen des Wächters.
     */
    public function test_internal_targets_stay_blocked(): void
    {
        foreach (['https://127.0.0.1/hook', 'https://169.254.169.254/latest/meta-data', 'http://example.test/hook', 'https://user:pw@example.test/hook'] as $url) {
            $this->assertFalse(OutboundUrlGuard::isAllowed($url), $url);
            $this->assertNull(OutboundUrlGuard::publicIpsFor($url), $url);
        }
    }
}

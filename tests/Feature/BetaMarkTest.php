<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Das Wortzeichen traegt ein hochgestelltes Beta.
 *
 * Auf den Buchungsseiten der Betriebe aber nicht: Dort tritt der Gastronom als
 * Anbieter auf, und ein Beta-Zeichen an seiner Buchungsstrecke verunsichert
 * seine Gaeste.
 */
class BetaMarkTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const ZEICHEN = 'β';

    public function test_the_marketing_pages_carry_the_beta_mark(): void
    {
        $this->get('/')->assertOk()->assertSee(self::ZEICHEN, false);
        $this->get('/kontakt')->assertOk()->assertSee(self::ZEICHEN, false);
    }

    public function test_the_sign_in_pages_carry_the_beta_mark(): void
    {
        $this->get('/login')->assertOk()->assertSee(self::ZEICHEN, false);
        $this->get('/register')->assertOk()->assertSee(self::ZEICHEN, false);
    }

    public function test_the_admin_area_carries_the_beta_mark(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee(self::ZEICHEN, false);
    }

    public function test_the_platform_area_carries_the_beta_mark(): void
    {
        $admin = User::factory()->create(['saas_role' => 'super_admin']);

        $this->actingAs($admin)->get('/saas')->assertOk()->assertSee(self::ZEICHEN, false);
    }

    /**
     * Der Punkt, auf den es ankommt.
     */
    public function test_a_guest_booking_page_stays_free_of_it(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug)
            ->assertOk()
            ->assertDontSee(self::ZEICHEN, false);
    }

    /**
     * Es muss das griechische Beta sein, nicht das Eszett - die beiden sehen
     * in manchen Schriften fast gleich aus.
     */
    public function test_the_mark_is_a_greek_beta_in_superscript(): void
    {
        $seite = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<sup[^>]*>β<\/sup>/u', $seite);
        $this->assertStringNotContainsString('<sup>ß</sup>', $seite);

        // U+03B2 (griechisches Beta), nicht U+00DF (Eszett).
        $this->assertSame('03b2', bin2hex(mb_convert_encoding(self::ZEICHEN, 'UTF-16BE', 'UTF-8')));
    }
}

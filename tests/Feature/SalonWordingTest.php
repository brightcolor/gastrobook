<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Einstellungsseiten eines Salons sagen „Kunde“ und „Kunden“, die eines
 * Restaurants „Gast“ und „Gäste“. Eine Seite, die in einem Salon „Gäste“
 * sagt, bricht den Wortlaut der Nachbarseiten.
 */
class SalonWordingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Alle Seiten mit Sätzen über Gäste oder Kunden. */
    private const PAGES = [
        '/admin/settings/notifications',
        '/admin/settings/sender',
        '/admin/settings/sms',
        '/admin/settings/payment-providers',
        '/admin/templates',
        '/admin/settings/deposits',
        '/admin/settings/newsletter',
        '/admin/api-tokens',
        '/admin/webhooks',
    ];

    private function owner(TenantType $type): User
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => $type]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return $owner;
    }

    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<string> Stellen mit Gast-Wörtern
     */
    private function guestWords(string $text): array
    {
        preg_match_all('/[^.\n]{0,40}\b(?:Gast|Gäste|Gästen|Gastes|Gästekommunikation|Abendtische)\b[^.\n]{0,20}/u', $text, $matches);

        return $matches[0];
    }

    public function test_the_settings_pages_of_a_salon_never_say_gast(): void
    {
        $owner = $this->owner(TenantType::Salon);

        foreach (self::PAGES as $page) {
            $text = $this->visibleText((string) $this->actingAs($owner)->get($page)->assertOk()->getContent());

            $this->assertSame([], $this->guestWords($text), $page);
        }
    }

    public function test_the_settings_pages_of_a_restaurant_never_say_kunde(): void
    {
        $owner = $this->owner(TenantType::Restaurant);

        foreach (self::PAGES as $page) {
            $text = $this->visibleText((string) $this->actingAs($owner)->get($page)->assertOk()->getContent());

            $this->assertDoesNotMatchRegularExpression('/\bKunde\b|\bKunden\b|Kundenkommunikation/u', $text, $page);
        }
    }

    public function test_a_salon_reads_the_group_and_the_crumb_as_kundenkommunikation(): void
    {
        $owner = $this->owner(TenantType::Salon);

        foreach (['/admin/settings/notifications', '/admin/settings/sender', '/admin/settings/sms', '/admin/templates'] as $page) {
            $this->actingAs($owner)->get($page)->assertOk()->assertSee('Einstellungen › Kundenkommunikation');
        }
        $this->actingAs($owner)->get('/admin/settings/menu')->assertOk()->assertSee('Kundenkommunikation')->assertDontSee('Gästekommunikation');
    }

    public function test_a_restaurant_keeps_gaestekommunikation(): void
    {
        $owner = $this->owner(TenantType::Restaurant);

        foreach (['/admin/settings/notifications', '/admin/settings/sender', '/admin/settings/sms', '/admin/templates'] as $page) {
            $this->actingAs($owner)->get($page)->assertOk()->assertSee('Einstellungen › Gästekommunikation');
        }
    }

    public function test_the_deposit_hint_for_the_time_of_day_fits_the_business_type(): void
    {
        $salon = $this->owner(TenantType::Salon);
        $this->actingAs($salon)->get('/admin/settings/deposits')->assertOk()
            ->assertSee('Die Regel gilt erst ab dieser Uhrzeit, etwa 17:00 für Termine am Abend. Leer: ganztags.')
            ->assertSee('So viel Prozent der Anzahlung bekommt der Kunde zurück.')
            ->assertDontSee('Abendtische');

        $restaurant = $this->owner(TenantType::Restaurant);
        $this->actingAs($restaurant)->get('/admin/settings/deposits')->assertOk()
            ->assertSee('Die Regel gilt erst ab dieser Uhrzeit, etwa 18:00 für Abendtische. Leer: ganztags.')
            ->assertSee('So viel Prozent der Anzahlung bekommt der Gast zurück.');
    }
}

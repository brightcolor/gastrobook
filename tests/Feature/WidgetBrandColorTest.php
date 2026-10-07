<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Buchungs-Widget und Buchungsseite nehmen die Markenfarbe des Betriebs; hat er
 * keine, gilt die Vorgabe aus den Einstellungen (swayy.ui.default_brand_color).
 */
class WidgetBrandColorTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_popup_falls_back_to_the_configured_default_color(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => null]);
        $this->clearTenantContext();

        $js = $this->get(route('booking.widget.popup', [$setup['tenant']->slug, $setup['location']->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('|| "#123456"', $js);
        $this->assertStringContainsString('var(--swayy-c,#123456)', $js);
    }

    public function test_the_popup_prefers_the_color_of_the_business(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#abcdef']);
        $this->clearTenantContext();

        $js = $this->get(route('booking.widget.popup', [$setup['tenant']->slug, $setup['location']->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('|| "#abcdef"', $js);
    }

    public function test_the_booking_page_falls_back_to_the_configured_default_color(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => null]);
        $this->clearTenantContext();

        $this->get(route('booking.show', [$setup['tenant']->slug, $setup['location']->slug]))
            ->assertOk()
            ->assertSee('--brand: #123456', false);
    }
}

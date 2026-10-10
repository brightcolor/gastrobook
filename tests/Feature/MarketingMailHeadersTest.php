<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Location;
use App\Services\GuestMailSender;
use App\Services\MarketingCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MarketingMailHeadersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array{0: Guest, 1: Location} */
    private function guest(): array
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'email' => 'gast@example.test', 'marketing_consent' => true]);

        return [$guest, $setup['location']];
    }

    public function test_campaign_mail_carries_one_click_unsubscribe_headers_and_footer_link(): void
    {
        [$guest, $location] = $this->guest();
        $url = app(MarketingCampaignService::class)->unsubscribeUrl($guest);

        $mail = app(GuestMailSender::class)->toGuest('Neue Karte', "Hallo,\n\nab Freitag gibt es Trüffel.\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$url}", $location->tenant, $location, type: 'marketing', unsubscribeUrl: $url);

        $headers = $mail->headers()->text;
        $this->assertSame('<'.$url.'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        $html = $mail->render();
        $this->assertStringContainsString('>Abmelden</a>', $html);
        $this->assertStringNotContainsString('Hier abmelden', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $mail->assertSeeInText('Hier abmelden: '.$url);
    }

    public function test_one_click_post_unsubscribes_with_a_valid_signature(): void
    {
        [$guest] = $this->guest();
        $url = app(MarketingCampaignService::class)->unsubscribeUrl($guest);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertFalse($guest->fresh()->marketing_consent);
        $this->assertDatabaseHas('guest_consents', ['guest_id' => $guest->id, 'type' => 'marketing', 'granted' => false]);
    }

    public function test_one_click_post_without_signature_changes_nothing(): void
    {
        [$guest] = $this->guest();

        $this->post('/marketing/abmelden/'.$guest->id, ['List-Unsubscribe' => 'One-Click'])->assertForbidden();

        $this->assertTrue($guest->fresh()->marketing_consent);
    }
}

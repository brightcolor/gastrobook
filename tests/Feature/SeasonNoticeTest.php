<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Außerhalb der Saison bekommt der Gast keinen leeren Kalender, sondern einen
 * warmen Hinweis mit dem nächsten Saisonstart.
 */
class SeasonNoticeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function prepare(array $setup, string $guestAddress = 'Sie'): void
    {
        $setup['location']->settings()->update([
            'max_advance_days' => 365, 'min_lead_minutes' => 0, 'guest_address' => $guestAddress,
        ]);
        $setup['location']->seasonPeriods()->create([
            'tenant_id' => $setup['tenant']->id,
            'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function slotsUrl(array $setup, string $date): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/slots?date='.$date.'&party_size=2';
    }

    public function test_out_of_season_the_response_names_the_next_opening(): void
    {
        $setup = $this->createTenantSetup();
        $this->prepare($setup);
        $this->clearTenantContext();
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00', $setup['location']->timezone));

        $resp = $this->getJson($this->slotsUrl($setup, '2026-12-02'))->assertOk();

        $resp->assertJsonPath('season_notice.next_date', '2027-04-01');
        $resp->assertJsonPath('season_notice.jump_label', 'Zum 1. April');

        // Gebucht werden kann schon jetzt – nur der gewählte Tag liegt außerhalb.
        // Der Text darf nicht klingen, als öffne die Buchung erst zum Saisonstart.
        $text = (string) $resp->json('season_notice.text');
        $this->assertStringContainsString('schon jetzt', $text);
        $this->assertStringContainsString('ab dem 1. April', $text);
        $this->assertStringNotContainsString('wieder ab dem', $text);
    }

    public function test_the_notice_follows_the_informal_tone(): void
    {
        $setup = $this->createTenantSetup();
        $this->prepare($setup, 'du');
        $this->clearTenantContext();
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00', $setup['location']->timezone));

        $text = (string) $this->getJson($this->slotsUrl($setup, '2026-12-02'))->assertOk()->json('season_notice.text');

        $this->assertStringContainsString('du da bist', $text);
        $this->assertStringContainsString('Reservieren kannst du schon jetzt', $text);
        $this->assertStringNotContainsString('Sie da sind', $text);
    }

    public function test_in_season_there_is_no_notice(): void
    {
        $setup = $this->createTenantSetup();
        $this->prepare($setup);
        $this->clearTenantContext();
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00', $setup['location']->timezone));

        $this->assertNull($this->getJson($this->slotsUrl($setup, '2026-07-01'))->assertOk()->json('season_notice'));
    }
}

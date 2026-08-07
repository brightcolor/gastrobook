<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\Reservation;
use App\Services\MarketingCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Automatic guest campaigns. Everything here is about *not* sending: no
 * consent, no visit at this location, already written to – each of those has
 * to stop a mail, or the feature turns into a spam machine.
 */
class MarketingCampaignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function campaign(array $setup, string $type, int $offset, array $attributes = []): MarketingCampaign
    {
        return MarketingCampaign::create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'type' => $type,
            'name' => 'Test',
            'is_active' => true,
            'offset_days' => $offset,
            'min_visits' => 1,
            'subject' => 'Hallo {first_name}',
            'body' => "Hallo {first_name},\nschön war's bei {location_name}.\nBuchen: {booking_link}\nAbmelden: {unsubscribe_link}",
        ], $attributes));
    }

    /** A guest who has been to this location and agreed to marketing. */
    private function guest(array $setup, array $attributes = []): Guest
    {
        $guest = Guest::factory()->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'marketing_consent' => true,
            'visit_count' => 3,
        ], $attributes));

        // Abgeschlossener Besuch: nur wer wirklich da war, zaehlt als Gast
        // dieses Standorts – eine blosse Buchung reicht nicht.
        Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
            'status' => ReservationStatus::Completed,
        ]);

        return $guest;
    }

    private function today(array $setup): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-06', $setup['location']->timezone)->startOfDay();
    }

    public function test_a_birthday_greeting_goes_out_three_days_early_and_only_once(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $guest = $this->guest($setup, ['birthday' => '1988-08-09']);
        $campaign = $this->campaign($setup, 'birthday', 3);
        $this->clearTenantContext();

        $service = app(MarketingCampaignService::class);
        $this->assertSame(1, $service->run($campaign, $this->today($setup)));

        Mail::assertQueued(TemplatedMail::class, 1);
        $this->assertSame(1, MarketingSend::withoutGlobalScopes()->where('guest_id', $guest->id)->count());

        // The job runs daily – a second pass must stay quiet.
        $this->assertSame(0, $service->run($campaign, $this->today($setup)));
        Mail::assertQueued(TemplatedMail::class, 1);
    }

    public function test_nobody_is_mailed_without_consent(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->guest($setup, ['birthday' => '1988-08-09', 'marketing_consent' => false]);
        $campaign = $this->campaign($setup, 'birthday', 3);
        $this->clearTenantContext();

        $this->assertSame(0, app(MarketingCampaignService::class)->run($campaign, $this->today($setup)));
        Mail::assertNothingQueued();
    }

    public function test_a_guest_who_never_visited_this_location_is_left_alone(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        // Same business, but the reservation belongs to another location.
        $other = $this->createTenantSetup();
        $guest = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'marketing_consent' => true,
            'visit_count' => 5,
            'birthday' => '1988-08-09',
        ]);
        Reservation::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $other['location']->id,
            'guest_id' => $guest->id,
        ]);
        $campaign = $this->campaign($setup, 'birthday', 3);
        $this->clearTenantContext();

        $this->assertSame(0, app(MarketingCampaignService::class)->run($campaign, $this->today($setup)));
        Mail::assertNothingQueued();
    }

    public function test_the_rebooking_reminder_fires_exactly_x_days_after_the_visit(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $today = $this->today($setup);
        $this->guest($setup, ['last_visit_at' => $today->subDays(42)]);
        $this->guest($setup, ['last_visit_at' => $today->subDays(10)]); // too early
        $campaign = $this->campaign($setup, 'rebooking', 42);
        $this->clearTenantContext();

        $this->assertSame(1, app(MarketingCampaignService::class)->run($campaign, $today));
        Mail::assertQueued(TemplatedMail::class, 1);
    }

    public function test_win_back_writes_at_most_once_a_year(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $today = $this->today($setup);
        $this->guest($setup, ['last_visit_at' => $today->subDays(400)]);
        $campaign = $this->campaign($setup, 'winback', 180);
        $this->clearTenantContext();

        $service = app(MarketingCampaignService::class);
        $this->assertSame(1, $service->run($campaign, $today));

        // A month later the guest still hasn't been back – but has already heard from us.
        $this->assertSame(0, $service->run($campaign, $today->addDays(30)));
        Mail::assertQueued(TemplatedMail::class, 1);
    }

    public function test_guests_with_too_few_visits_are_skipped(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->guest($setup, ['birthday' => '1988-08-09', 'visit_count' => 1]);
        $campaign = $this->campaign($setup, 'birthday', 3, ['min_visits' => 3]);
        $this->clearTenantContext();

        $this->assertSame(0, app(MarketingCampaignService::class)->run($campaign, $this->today($setup)));
        Mail::assertNothingQueued();
    }

    public function test_the_opt_out_link_revokes_consent_and_is_recorded(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guest($setup);
        $this->clearTenantContext();

        $url = URL::signedRoute('marketing.unsubscribe', ['guest' => $guest->id]);

        $this->get($url)->assertOk()->assertSee('Abgemeldet');

        $this->assertFalse((bool) Guest::withoutGlobalScope('tenant')->find($guest->id)->marketing_consent);
        $this->assertSame(1, GuestConsent::withoutGlobalScopes()
            ->where('guest_id', $guest->id)->where('granted', false)->count());
    }

    public function test_a_tampered_opt_out_link_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guest($setup);
        $this->clearTenantContext();

        $this->get('/marketing/abmelden/'.$guest->id.'?signature=egal')->assertForbidden();
        $this->assertTrue((bool) Guest::withoutGlobalScope('tenant')->find($guest->id)->marketing_consent);
    }

    public function test_a_new_campaign_starts_paused(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/marketing', [
            'type' => 'birthday',
            'name' => 'Geburtstag',
            'offset_days' => 3,
            'min_visits' => 1,
            'subject' => 'Alles Gute',
            'body' => 'Hallo {first_name}',
        ])->assertRedirect();

        $campaign = MarketingCampaign::withoutGlobalScopes()->firstOrFail();
        $this->assertFalse($campaign->is_active);

        $this->actingAs($admin)->post('/admin/marketing/'.$campaign->id.'/toggle')->assertRedirect();
        $this->assertTrue(MarketingCampaign::withoutGlobalScopes()->findOrFail($campaign->id)->is_active);
    }

    public function test_service_staff_cannot_open_the_marketing_page(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/marketing')->assertForbidden();
    }

    public function test_the_test_mail_goes_to_the_logged_in_user(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $campaign = $this->campaign($setup, 'birthday', 3, ['is_active' => false]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/marketing/'.$campaign->id.'/test')->assertRedirect();

        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo($admin->email));
    }
}

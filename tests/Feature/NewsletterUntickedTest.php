<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncNewsletterSubscriber;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestConsent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Buchung ohne Haken bei „Newsletter“: Eine erteilte Einwilligung bleibt
 * bestehen, außer der Betrieb zählt den leeren Haken als Abmeldung
 * (Einstellungen › Betrieb › Allgemein › Newsletter-Einwilligung). Der Gast
 * widerruft über den Abmeldelink.
 */
class NewsletterUntickedTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $data
     */
    private function regular(array $setup, array $data = []): Guest
    {
        return Guest::withoutGlobalScopes()->create($data + [
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Ida',
            'last_name' => 'Kessler',
            'email' => 'kessler@example.test',
            'marketing_consent' => true,
            'marketing_consent_at' => now()->subMonth(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $extra
     */
    private function bookTable(array $setup, array $extra = []): void
    {
        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, $extra + [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Ida Kessler',
            'email' => 'kessler@example.test',
            'phone' => '+49 30 000000',
            'privacy_accepted' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function withdrawals(Guest $guest): int
    {
        return GuestConsent::withoutGlobalScopes()
            ->where('guest_id', $guest->id)
            ->where('type', 'newsletter')
            ->where('granted', false)
            ->count();
    }

    public function test_a_booking_without_the_box_keeps_the_consent(): void
    {
        Mail::fake();
        Bus::fake([SyncNewsletterSubscriber::class]);

        $setup = $this->createTenantSetup();
        $guest = $this->regular($setup);
        $this->clearTenantContext();

        $this->bookTable($setup);

        $this->assertTrue($guest->fresh()->marketing_consent, 'Die zweite Buchung ohne Haken hat die Einwilligung widerrufen.');
        $this->assertSame(0, $this->withdrawals($guest));
        Bus::assertNotDispatched(SyncNewsletterSubscriber::class);
    }

    public function test_with_the_setting_a_missing_box_withdraws(): void
    {
        Mail::fake();
        Bus::fake([SyncNewsletterSubscriber::class]);

        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['newsletter_unticked_revokes' => true]);
        $guest = $this->regular($setup);
        $this->clearTenantContext();

        $this->bookTable($setup);

        $this->assertFalse($guest->fresh()->marketing_consent);
        $this->assertSame(1, $this->withdrawals($guest));
        // Der Gast stand im Verteiler, also wird er dort ausgetragen.
        Bus::assertDispatched(SyncNewsletterSubscriber::class);
    }

    public function test_a_ticked_box_grants_the_consent(): void
    {
        Mail::fake();
        Bus::fake([SyncNewsletterSubscriber::class]);

        $setup = $this->createTenantSetup();
        $guest = $this->regular($setup, ['marketing_consent' => false, 'marketing_consent_at' => null]);
        $this->clearTenantContext();

        $this->bookTable($setup, ['newsletter' => '1']);

        $this->assertTrue($guest->fresh()->marketing_consent);
        Bus::assertDispatched(SyncNewsletterSubscriber::class);
    }

    public function test_an_event_booking_without_the_box_keeps_the_consent(): void
    {
        Mail::fake();
        Bus::fake([SyncNewsletterSubscriber::class]);

        $setup = $this->createTenantSetup();
        $guest = $this->regular($setup);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(7)->setTime(19, 0);
        $event = Event::withoutGlobalScope('tenant')->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'title' => 'Weinprobe',
            'slug' => 'weinprobe',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 10,
            'price_minor' => 5000,
            'currency' => 'EUR',
            'is_public' => true,
            'status' => 'published',
        ]);
        $this->clearTenantContext();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/events/'.$event->slug, [
            'ticket_count' => 2,
            'name' => 'Ida Kessler',
            'email' => 'kessler@example.test',
            'privacy_accepted' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($guest->fresh()->marketing_consent);
        $this->assertSame(0, $this->withdrawals($guest));
    }

    public function test_the_business_switches_the_withdrawal_on_and_off(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson('/admin/settings/newsletter-consent', ['newsletter_unticked_revokes' => '1'])
            ->assertOk()->assertJsonPath('message', 'Newsletter-Einwilligung gespeichert.');
        $this->assertTrue($setup['tenant']->fresh()->newsletter_unticked_revokes);
        $log = AuditLog::withoutGlobalScopes()->where('action', 'tenant.newsletter_consent_updated')->sole();
        $this->assertSame(['newsletter_unticked_revokes' => false], $log->old_values);
        $this->assertSame(['newsletter_unticked_revokes' => true], $log->new_values);

        $this->actingAs($admin)->putJson('/admin/settings/newsletter-consent', ['newsletter_unticked_revokes' => '0'])->assertOk();
        $this->assertFalse($setup['tenant']->fresh()->newsletter_unticked_revokes);
    }

    public function test_a_request_without_the_field_keeps_the_stored_state(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['newsletter_unticked_revokes' => true]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson('/admin/settings/newsletter-consent', [])->assertOk();

        $this->assertTrue($setup['tenant']->fresh()->newsletter_unticked_revokes);
    }

    public function test_an_unusable_value_gets_a_clear_message(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->from('/admin/settings/general')
            ->put('/admin/settings/newsletter-consent', ['newsletter_unticked_revokes' => 'vielleicht'])
            ->assertSessionHasErrors(['newsletter_unticked_revokes' => 'Die Einstellung zum Newsletter-Haken kennt nur an oder aus. Bitte den Schalter neu setzen und speichern.']);

        $this->assertFalse($setup['tenant']->fresh()->newsletter_unticked_revokes);
    }

    public function test_staff_may_not_change_the_setting(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->put('/admin/settings/newsletter-consent', ['newsletter_unticked_revokes' => '1'])
            ->assertForbidden();

        $this->assertFalse($setup['tenant']->fresh()->newsletter_unticked_revokes);
    }

    public function test_the_general_page_shows_the_switch_in_the_words_of_the_business(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get(route('admin.settings.general.show'))
            ->assertOk()
            ->assertSee('Newsletter-Einwilligung')
            ->assertSee('name="newsletter_unticked_revokes" value="0"', false)
            ->assertSee('id="f-newsletter_unticked_revokes"', false)
            ->assertSee('ohne Haken den Gast vom Newsletter ab', false);

        $setup['tenant']->update(['type' => 'salon']);
        $this->actingAs($admin)->get(route('admin.settings.general.show'))
            ->assertSee('ohne Haken den Kunden vom Newsletter ab', false)
            ->assertDontSee('Mails an Gäste', false);
    }
}

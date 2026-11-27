<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\IntegrationConnection;
use App\Services\GuestProfileService;
use App\Services\Newsletter\NewsletterManager;
use App\Support\OutboundUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Anmeldung zum Newsletter über Swayy: eigener Häkchentext je Betrieb samt
 * Nachweis, Herkunft in MailWizz, die Aufrufe der MailWizz-Schnittstelle,
 * Abmeldung nur über den Link und der Hinweis auf die Bestätigungsmail.
 */
class NewsletterSignupTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const API = 'https://news.example.test/api/lists/ab12cd34';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        OutboundUrlGuard::resolveUsing(fn (string $host) => $host === 'news.example.test' ? ['93.184.216.34'] : []);
    }

    private function connect(int $tenantId): IntegrationConnection
    {
        return IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'provider' => 'mailwizz',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString((string) json_encode([
                'api_url' => 'https://news.example.test/api', 'api_key' => 'test-key', 'list_uid' => 'ab12cd34',
            ])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $extra
     */
    private function book(array $setup, array $extra = [])
    {
        return $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString(),
            'time' => '19:00', 'party_size' => 2, 'name' => 'Nadine Newsletter',
            'email' => 'nadine@example.test', 'phone' => '+49 170 8888888', 'privacy_accepted' => '1',
            ...$extra,
        ]);
    }

    private function guest(array $setup, array $attributes = []): Guest
    {
        return Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'first_name' => 'Nadine', 'last_name' => 'Newsletter',
            'email' => 'nadine@example.test', ...$attributes,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data()])->all();
    }

    public function test_the_business_text_is_shown_and_kept_as_proof(): void
    {
        Http::fake(['news.example.test/*' => Http::response(['status' => 'success'], 201)]);
        $setup = $this->createTenantSetup();
        $text = 'Ja, ich will den Newsletter von HSP-Events! <b>Abmelden</b> geht jederzeit.';
        $setup['tenant']->update(['newsletter_consent_text' => $text]);
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()->assertSee($text)->assertDontSee('Newsletter mit Angeboten erhalten');

        $this->book($setup, ['newsletter' => '1'])->assertRedirect();

        $consent = GuestConsent::withoutGlobalScopes()->where('type', 'newsletter')->firstOrFail();
        $this->assertTrue($consent->granted);
        $this->assertSame($text, $consent->consent_text);
    }

    public function test_without_own_text_the_default_is_shown_and_kept(): void
    {
        Http::fake(['news.example.test/*' => Http::response([], 201)]);
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();
        $default = 'Newsletter mit Angeboten erhalten (jederzeit widerrufbar).';

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)->assertSee($default);
        $this->book($setup, ['newsletter' => '1']);

        $this->assertSame($default, GuestConsent::withoutGlobalScopes()->where('type', 'newsletter')->value('consent_text'));
    }

    public function test_a_new_signup_carries_source_and_event(): void
    {
        Http::fake(['news.example.test/*' => Http::response(['status' => 'success'], 201)]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->book($setup, ['newsletter' => '1']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::API.'/subscribers'
            && $request['EMAIL'] === 'nadine@example.test'
            && $request['QUELLE'] === 'swayy'
            && $request['VERANSTALTUNG'] === $setup['tenant']->name
            && ! isset($request->data()['details']));
    }

    public function test_tags_and_values_come_from_the_settings_and_can_be_switched_off(): void
    {
        Http::fake(['news.example.test/*' => Http::response([], 201)]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $setup['tenant']->update(['newsletter_fields' => ['source_tag' => 'HERKUNFT', 'source_value' => 'Buchung', 'event_tag' => '', 'event_value' => 'Sternenwald']]);
        $this->clearTenantContext();

        $this->book($setup, ['newsletter' => '1']);

        Http::assertSent(fn (Request $request) => $request['HERKUNFT'] === 'Buchung'
            && ! array_key_exists('QUELLE', $request->data())
            && ! array_key_exists('VERANSTALTUNG', $request->data()));
    }

    public function test_a_known_subscriber_keeps_the_first_source(): void
    {
        Http::fake([
            self::API.'/subscribers/search-by-email*' => Http::response(['status' => 'success', 'data' => ['subscriber_uid' => 'sub123', 'status' => 'confirmed']], 200),
            self::API.'/subscribers/sub123' => Http::response(['status' => 'success'], 200),
            self::API.'/subscribers' => Http::response(['status' => 'error', 'error' => 'The subscriber already exists in this list.'], 409),
        ]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();
        $guest = $this->guest($setup, ['marketing_consent' => true]);

        $this->assertTrue(app(NewsletterManager::class)->providerFor($setup['tenant'])->subscribe($guest));

        $update = collect($this->sent())->first(fn (array $call) => $call[0] === 'PUT');
        $this->assertSame(self::API.'/subscribers/sub123', $update[1]);
        $this->assertSame(['EMAIL' => 'nadine@example.test', 'FNAME' => 'Nadine', 'LNAME' => 'Newsletter'], $update[2]);
    }

    public function test_unsubscribing_looks_up_the_subscriber_and_uses_its_id(): void
    {
        Http::fake([
            self::API.'/subscribers/search-by-email*' => Http::response(['data' => ['subscriber_uid' => 'sub123', 'status' => 'confirmed']], 200),
            self::API.'/subscribers/sub123/unsubscribe' => Http::response(['status' => 'success'], 200),
        ]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->assertTrue(app(NewsletterManager::class)->providerFor($setup['tenant'])->unsubscribe($this->guest($setup)));

        $this->assertSame(['GET', 'PUT'], array_column($this->sent(), 0));
        $this->assertSame(self::API.'/subscribers/sub123/unsubscribe', $this->sent()[1][1]);
    }

    public function test_unsubscribing_an_unknown_address_succeeds_only_if_the_list_exists(): void
    {
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();
        $guest = $this->guest($setup);
        $listStatus = 200;
        Http::fake([
            self::API.'/subscribers/search-by-email*' => Http::response(['status' => 'error'], 404),
            self::API => function () use (&$listStatus) {
                return Http::response([], $listStatus);
            },
        ]);
        $provider = app(NewsletterManager::class)->providerFor($setup['tenant']);

        $this->assertTrue($provider->unsubscribe($guest));

        $listStatus = 404;
        $this->assertFalse($provider->unsubscribe($guest));
    }

    public function test_the_unsubscribe_link_also_unsubscribes_at_the_newsletter_system(): void
    {
        Http::fake([
            self::API.'/subscribers/search-by-email*' => Http::response(['data' => ['subscriber_uid' => 'sub123', 'status' => 'confirmed']], 200),
            self::API.'/subscribers/sub123/unsubscribe' => Http::response(['status' => 'success'], 200),
        ]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();
        $guest = $this->guest($setup, ['marketing_consent' => true]);

        $this->get(URL::signedRoute('marketing.unsubscribe', ['guest' => $guest->id]))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/subscribers/sub123/unsubscribe');
        $this->assertFalse($guest->fresh()->marketing_consent);
    }

    public function test_a_later_booking_without_the_box_changes_nothing(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();
        $guest = $this->guest($setup, ['marketing_consent' => true]);

        $this->book($setup)->assertRedirect();

        Http::assertNothingSent();
        $this->assertTrue($guest->fresh()->marketing_consent);
        $this->assertSame(0, GuestConsent::withoutGlobalScopes()->where('type', 'newsletter')->count());
    }

    public function test_consents_recorded_by_the_team_carry_no_text(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        app(GuestProfileService::class)->recordConsent($this->guest($setup), 'newsletter', true, 'correction');

        $this->assertNull(GuestConsent::withoutGlobalScopes()->value('consent_text'));
    }

    public function test_after_booking_the_page_names_the_newsletter_mail_its_button_and_the_spam_folder(): void
    {
        Http::fake(['news.example.test/*' => Http::response([], 201)]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $setup['tenant']->update(['newsletter_confirm_button' => 'Ja, ich will mehr!']);
        $this->clearTenantContext();

        $page = $this->followingRedirects()->book($setup, ['newsletter' => '1']);

        $page->assertSee('data-newsletter-hint', false)
            ->assertSee('Ja, ich will mehr!')
            ->assertSee('Spam- oder Werbeordner');
    }

    public function test_no_newsletter_hint_without_box_connection_or_confirmation_mail(): void
    {
        Http::fake(['news.example.test/*' => Http::response([], 201)]);

        // Ohne Häkchen.
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $this->clearTenantContext();
        $this->followingRedirects()->book($setup)->assertDontSee('data-newsletter-hint', false);

        // Ohne Anbindung.
        $this->app['session']->flush();
        $other = $this->createTenantSetup();
        $this->clearTenantContext();
        $this->followingRedirects()->book($other, ['newsletter' => '1'])->assertDontSee('data-newsletter-hint', false);

        // Liste ohne Bestätigungsmail.
        $this->app['session']->flush();
        $third = $this->createTenantSetup();
        $this->connect($third['tenant']->id);
        $third['tenant']->update(['newsletter_double_opt_in' => false]);
        $this->clearTenantContext();
        $this->followingRedirects()->book($third, ['newsletter' => '1'])->assertDontSee('data-newsletter-hint', false);
    }

    public function test_an_event_booking_shows_the_text_keeps_it_and_names_the_newsletter_mail(): void
    {
        Http::fake(['news.example.test/*' => Http::response([], 201)]);
        $setup = $this->createTenantSetup();
        $this->connect($setup['tenant']->id);
        $text = 'Ja, ich will den Newsletter von HSP-Events!';
        $setup['tenant']->update(['newsletter_consent_text' => $text]);
        $event = Event::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinabend', 'slug' => 'weinabend', 'capacity' => 20,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(3),
            'status' => 'published', 'currency' => 'EUR', 'is_public' => true,
        ]);
        $this->clearTenantContext();
        $path = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/events/'.$event->slug;

        $this->get($path)->assertOk()->assertSee($text);
        $page = $this->followingRedirects()->post($path, [
            'ticket_count' => 2, 'name' => 'Nadine Newsletter', 'email' => 'nadine@example.test',
            'privacy_accepted' => '1', 'newsletter' => '1',
        ]);

        $page->assertOk()->assertSee('data-newsletter-hint', false)->assertSee('Spam- oder Werbeordner');
        $this->assertSame($text, GuestConsent::withoutGlobalScopes()->where('type', 'newsletter')->value('consent_text'));
    }

    public function test_the_team_saves_text_button_and_field_names_with_checks(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/newsletter-signup', [
            'newsletter_consent_text' => 'Ja, ich will den Newsletter von HSP-Events!',
            'newsletter_confirm_button' => 'Ja, ich will mehr!',
            'newsletter_double_opt_in' => '1',
            'source_tag' => 'quelle', 'source_value' => 'swayy', 'event_tag' => 'VERANSTALTUNG', 'event_value' => 'Sternenwald',
        ])->assertOk();

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Ja, ich will den Newsletter von HSP-Events!', $tenant->newsletter_consent_text);
        $this->assertSame('Ja, ich will mehr!', $tenant->newsletter_confirm_button);
        $this->assertSame(['source_tag' => 'QUELLE', 'source_value' => 'swayy', 'event_tag' => 'VERANSTALTUNG', 'event_value' => 'Sternenwald'], $tenant->newsletter_fields);

        $this->actingAs($owner)->putJson('/admin/settings/newsletter-signup', ['source_tag' => 'EMAIL', 'event_tag' => 'mit leerzeichen'])
            ->assertStatus(422)->assertJsonValidationErrors(['source_tag', 'event_tag']);

        config(['swayy.newsletter.consent_text_max' => 40]);
        $this->actingAs($owner)->putJson('/admin/settings/newsletter-signup', ['newsletter_consent_text' => str_repeat('a', 41)])
            ->assertStatus(422)->assertJsonValidationErrors('newsletter_consent_text');
    }
}

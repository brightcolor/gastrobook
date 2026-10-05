<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncNewsletterSubscriber;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Newsletter\NewsletterManager;
use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die MailWizz-Adresse traegt der Betrieb selbst ein, und der Server ruft sie
 * auf. Sie geht deshalb durch dieselbe Zielpruefung wie Webhook-Endpunkte:
 * beim Speichern, vor jeder Anfrage und beim Verbindungstest.
 */
class MailwizzTargetCheckTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve([
            'news.example.test' => ['93.184.216.34'],
            'intern.example.test' => ['192.168.1.20'],
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedTargets(): array
    {
        return [
            'Loopback' => ['https://127.0.0.1/api', OutboundUrlBlocked::INTERNAL],
            'privates Netz' => ['https://10.0.0.5/api', OutboundUrlBlocked::INTERNAL],
            'Metadatendienst' => ['https://169.254.169.254/latest/meta-data', OutboundUrlBlocked::INTERNAL],
            'Name auf internes Netz' => ['https://intern.example.test/api', OutboundUrlBlocked::INTERNAL],
            'http' => ['http://news.example.test/api', OutboundUrlBlocked::SCHEME],
            'Zugangsdaten' => ['https://user:pw@news.example.test/api', OutboundUrlBlocked::CREDENTIALS],
            'nicht aufloesbar' => ['https://unbekannt.example.test/api', OutboundUrlBlocked::UNRESOLVABLE],
        ];
    }

    #[DataProvider('refusedTargets')]
    public function test_saving_refuses_the_target_and_says_why(string $url, string $reason): void
    {
        Http::fake();
        [$tenant, $admin] = $this->tenantWithAdmin();

        $this->actingAs($admin)->putJson('/admin/settings/mailwizz', [
            'api_url' => $url,
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.api_url.0', OutboundUrlBlocked::because($reason)->getMessage());

        Http::assertNothingSent();
        $this->assertNull($this->connection($tenant));
    }

    public function test_the_form_shows_the_reason_after_a_redirect_as_well(): void
    {
        Http::fake();
        [, $admin] = $this->tenantWithAdmin();

        $this->actingAs($admin)->put('/admin/settings/mailwizz', [
            'api_url' => 'https://127.0.0.1/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
        ])->assertRedirect()
            ->assertSessionHasErrors(['api_url' => OutboundUrlBlocked::because(OutboundUrlBlocked::INTERNAL)->getMessage()]);

        Http::assertNothingSent();
    }

    /**
     * Der Verbindungstest geht genau an die Adresse, die die Pruefung gesehen
     * hat, und folgt keiner Weiterleitung.
     */
    public function test_a_public_target_is_saved_and_the_connection_test_is_pinned(): void
    {
        $this->fakeMailwizz(Http::response(['status' => 'success'], 200));
        [$tenant, $admin] = $this->tenantWithAdmin();

        $this->actingAs($admin)->putJson('/admin/settings/mailwizz', [
            'api_url' => 'https://news.example.test/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertOk()->assertJsonPath('message', 'MailWizz-Integration gespeichert und Verbindung getestet.');

        $this->assertSame('connected', $this->connection($tenant)?->status);
        $this->assertCount(1, $this->options);
        $this->assertSame(['news.example.test:443:93.184.216.34'], $this->options[0]['curl'][CURLOPT_RESOLVE] ?? null);
        $this->assertFalse($this->options[0]['allow_redirects']);
        $this->assertSame(10, $this->options[0]['timeout']);
    }

    public function test_the_connection_test_does_not_follow_a_redirect(): void
    {
        $this->fakeMailwizz(Http::response('', 302, ['Location' => 'https://10.0.0.5/api/lists/xy99zz88']));
        [$tenant, $admin] = $this->tenantWithAdmin();

        $antwort = $this->actingAs($admin)->putJson('/admin/settings/mailwizz', [
            'api_url' => 'https://news.example.test/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertStatus(422);

        $this->assertStringContainsString('leitet die Anfrage an eine andere Adresse weiter', $antwort->json('errors.api_url.0'));
        $this->assertCount(1, $this->options, 'Der Weiterleitung darf niemand folgen.');

        $connection = $this->connection($tenant);
        $this->assertSame('error', $connection?->status);
        $this->assertSame($antwort->json('errors.api_url.0'), $connection->settings['last_error'] ?? null);
    }

    /**
     * Eine Adresse, die vor der Pruefung gespeichert wurde, kommt beim Aufruf
     * nicht mehr durch. Die Anbindung haelt an und sagt warum.
     */
    public function test_the_sync_refuses_an_internal_target_saved_earlier(): void
    {
        Http::fake();
        [$tenant] = $this->tenantWithAdmin();
        $this->connect($tenant, 'https://10.0.0.5/api');
        $guest = $this->guestWithConsent($tenant);

        SyncNewsletterSubscriber::dispatchSync($guest->id);

        Http::assertNothingSent();
        $this->assertSuspendedWith($tenant, OutboundUrlBlocked::INTERNAL);
        $this->assertNull(app(NewsletterManager::class)->providerFor($tenant), 'Eine angehaltene Anbindung versucht es nicht weiter.');
    }

    /**
     * DNS-Rebinding: Beim Speichern zeigte der Name nach aussen, beim Aufruf
     * nach innen. Gilt, was der Aufruf sieht.
     */
    public function test_the_sync_checks_the_name_again_before_each_request(): void
    {
        Http::fake();
        [$tenant] = $this->tenantWithAdmin();
        $this->connect($tenant, 'https://news.example.test/api');
        $guest = $this->guestWithConsent($tenant);

        $this->resolve(['news.example.test' => ['10.0.0.7']]);

        SyncNewsletterSubscriber::dispatchSync($guest->id);

        Http::assertNothingSent();
        $this->assertSuspendedWith($tenant, OutboundUrlBlocked::INTERNAL);
    }

    public function test_the_sync_sends_to_the_checked_address_only(): void
    {
        $this->fakeMailwizz(Http::response(['status' => 'success'], 201));
        [$tenant] = $this->tenantWithAdmin();
        $this->connect($tenant, 'https://news.example.test/api');
        $guest = $this->guestWithConsent($tenant);

        SyncNewsletterSubscriber::dispatchSync($guest->id);

        $this->assertCount(1, $this->options);
        $this->assertSame(['news.example.test:443:93.184.216.34'], $this->options[0]['curl'][CURLOPT_RESOLVE] ?? null);
        $this->assertFalse($this->options[0]['allow_redirects']);
        $this->assertSame('connected', $this->connection($tenant)?->status);
        $this->assertDatabaseHas('notification_logs', ['recipient' => $guest->email, 'status' => 'sent']);
    }

    /**
     * Eine gestoerte Namensaufloesung ist voruebergehend: Der Job versucht es
     * spaeter erneut, und die Anbindung bleibt eingeschaltet.
     */
    public function test_an_unresolvable_name_is_retried_and_the_integration_stays_on(): void
    {
        Http::fake();
        config(['swayy.newsletter.backoff' => [45, 900]]);
        [$tenant] = $this->tenantWithAdmin();
        $this->connect($tenant, 'https://news.example.test/api');
        $guest = $this->guestWithConsent($tenant);

        $this->resolve([]);

        $job = (new SyncNewsletterSubscriber($guest->id))->withFakeQueueInteractions();
        $job->handle(app(NewsletterManager::class));

        $job->assertReleased(45);
        Http::assertNothingSent();
        $this->assertSame('connected', $this->connection($tenant)?->status);

        $log = NotificationLog::withoutGlobalScopes()->where('recipient', $guest->email)->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString(OutboundUrlBlocked::because(OutboundUrlBlocked::UNRESOLVABLE)->getMessage(), (string) $log->error);
    }

    /**
     * Wer MailWizz bewusst intern betreibt, gibt genau dieses Netz frei.
     */
    public function test_an_allowed_internal_network_can_be_used_on_purpose(): void
    {
        config(['swayy.outbound.allowed_networks' => ['10.20.0.0/16']]);
        $this->resolve(['mailwizz.intern.test' => ['10.20.0.9']]);
        $this->fakeMailwizz(Http::response(['status' => 'success'], 200));
        [$tenant, $admin] = $this->tenantWithAdmin();

        $this->actingAs($admin)->putJson('/admin/settings/mailwizz', [
            'api_url' => 'https://mailwizz.intern.test/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertOk();

        $this->assertSame('connected', $this->connection($tenant)?->status);
        $this->assertSame(['mailwizz.intern.test:443:10.20.0.9'], $this->options[0]['curl'][CURLOPT_RESOLVE] ?? null);
    }

    public function test_timeout_tries_and_backoff_come_from_the_settings(): void
    {
        config([
            'swayy.newsletter.timeout' => 3,
            'swayy.newsletter.tries' => 5,
            'swayy.newsletter.backoff' => [30, 90],
        ]);
        $this->fakeMailwizz(Http::response(['status' => 'success'], 200));
        [, $admin] = $this->tenantWithAdmin();

        $this->actingAs($admin)->putJson('/admin/settings/mailwizz', [
            'api_url' => 'https://news.example.test/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertOk();

        $this->assertSame(3, $this->options[0]['timeout']);

        $job = new SyncNewsletterSubscriber(1);
        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 90], $job->backoff);
    }

    public function test_the_settings_page_shows_why_the_integration_stopped(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin();
        $grund = OutboundUrlBlocked::because(OutboundUrlBlocked::INTERNAL)->getMessage();
        IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'mailwizz',
            'status' => 'error',
            'settings' => ['last_error' => $grund],
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'api_url' => 'https://10.0.0.5/api', 'api_key' => 'test-key', 'list_uid' => 'ab12cd34',
            ])),
        ]);

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee($grund);
    }

    /**
     * @return array{Tenant, User}
     */
    private function tenantWithAdmin(): array
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        return [$setup['tenant'], $admin];
    }

    private function connect(Tenant $tenant, string $apiUrl): void
    {
        IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'mailwizz',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'api_url' => $apiUrl,
                'api_key' => 'test-key',
                'list_uid' => 'ab12cd34',
            ])),
        ]);
    }

    private function guestWithConsent(Tenant $tenant): Guest
    {
        return Guest::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'gast@example.test',
            'marketing_consent' => true,
        ]);
    }

    private function connection(Tenant $tenant): ?IntegrationConnection
    {
        return IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'mailwizz')
            ->first();
    }

    private function assertSuspendedWith(Tenant $tenant, string $reason): void
    {
        $grund = OutboundUrlBlocked::because($reason)->getMessage();

        $connection = $this->connection($tenant);
        $this->assertSame('error', $connection?->status);
        $this->assertSame($grund, $connection->settings['last_error'] ?? null);

        $log = NotificationLog::withoutGlobalScopes()->where('channel', 'newsletter')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString($grund, (string) $log->error);
    }

    /**
     * Antwortet fuer jede Anfrage gleich und merkt sich die Optionen, mit
     * denen der Client sie abgeschickt haette.
     */
    private function fakeMailwizz(mixed $response): void
    {
        Http::fake(function ($request, array $options) use ($response) {
            $this->options[] = $options;

            return $response;
        });
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private function resolve(array $map): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host) => $map[$host] ?? []);
    }
}

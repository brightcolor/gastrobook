<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Zustellung eines Webhooks. Lehnt die Zielpruefung die Adresse selbst ab,
 * schaltet sich der Endpunkt ab. Laesst sich der Name nur gerade nicht
 * aufloesen, folgt ein spaeterer Versuch, und der Endpunkt bleibt an.
 */
class WebhookDeliveryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve([
            'hooks.example.test' => ['93.184.216.34'],
            'intern.example.test' => ['10.0.0.7'],
        ]);
    }

    public function test_a_name_that_does_not_resolve_right_now_is_retried_later(): void
    {
        Http::fake();
        config(['swayy.webhooks.backoff' => [45, 900]]);
        [$endpoint, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');
        $this->resolve([]);

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(45);
        Http::assertNothingSent();

        $endpoint->refresh();
        $this->assertTrue($endpoint->is_active);
        $this->assertNull($endpoint->disabled_at);
        $this->assertSame(0, $endpoint->failure_count);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString(OutboundUrlBlocked::because(OutboundUrlBlocked::UNRESOLVABLE)->getMessage(), (string) $delivery->response_body);
    }

    /**
     * Auch ein Name, der sich nie aufloesen laesst, endet nach dem letzten
     * Versuch als ein gescheitertes Ereignis und zaehlt zur Abschaltschwelle.
     */
    public function test_after_the_last_try_an_unresolvable_name_counts_as_one_failed_event(): void
    {
        Http::fake();
        config(['swayy.webhooks.tries' => 2, 'swayy.webhooks.disable_after' => 1]);
        [$endpoint, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');
        $this->resolve([]);

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle();

        $job->assertNotReleased();
        $endpoint->refresh();
        $this->assertSame(1, $endpoint->failure_count);
        $this->assertFalse($endpoint->is_active);
    }

    public function test_an_address_the_check_refuses_switches_the_endpoint_off_with_the_reason(): void
    {
        Http::fake();
        [$endpoint, $delivery] = $this->endpointWithDelivery('https://intern.example.test/swayy');

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        Http::assertNothingSent();

        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertNotNull($endpoint->disabled_at);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('Endpunkt abgeschaltet', (string) $delivery->response_body);
        $this->assertStringContainsString(OutboundUrlBlocked::because(OutboundUrlBlocked::INTERNAL)->getMessage(), (string) $delivery->response_body);
    }

    public function test_a_paused_endpoint_says_how_to_switch_it_on_again(): void
    {
        Http::fake();
        [$endpoint, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');
        $endpoint->update(['is_active' => false]);

        (new DeliverWebhook($delivery->id))->withFakeQueueInteractions()->handle();

        Http::assertNothingSent();
        $this->assertSame(
            'Nicht gesendet: Der Endpunkt ist pausiert. Unter „Webhooks“ lässt er sich wieder aktivieren.',
            $delivery->fresh()->response_body
        );
    }

    public function test_an_unreachable_receiver_is_retried_and_explained(): void
    {
        config(['swayy.webhooks.backoff' => [30]]);
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        [$endpoint, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(30);
        $this->assertTrue($endpoint->fresh()->is_active);
        $this->assertSame(
            'Nicht zugestellt: Die Gegenstelle war nicht erreichbar oder hat nicht rechtzeitig geantwortet. Technische Angabe: cURL error 28: Operation timed out',
            $delivery->fresh()->response_body
        );
    }

    public function test_tries_backoff_and_timeout_come_from_the_settings(): void
    {
        config([
            'swayy.webhooks.timeout' => 4,
            'swayy.webhooks.tries' => 3,
            'swayy.webhooks.backoff' => [20, 40],
        ]);
        $this->fakeReceiver(Http::response('kaputt', 500));
        [, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $this->assertSame(3, $job->tries);
        $this->assertSame([20, 40], $job->backoff);

        $job->handle();

        $job->assertReleased(20);
        $this->assertCount(1, $this->options);
        $this->assertSame(4, $this->options[0]['timeout']);
        $this->assertSame(['hooks.example.test:443:93.184.216.34'], $this->options[0]['curl'][CURLOPT_RESOLVE] ?? null);
        $this->assertFalse($this->options[0]['allow_redirects']);
    }

    /**
     * Beim letzten Versuch wird nichts mehr zurueckgestellt. Der Job endet,
     * das Ereignis steht als gescheitert im Protokoll.
     */
    public function test_the_last_try_is_not_put_back_into_the_queue(): void
    {
        config(['swayy.webhooks.tries' => 2]);
        Http::fake(['*' => Http::response('kaputt', 500)]);
        [, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');

        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle();

        $job->assertNotReleased();
        $this->assertSame(500, $delivery->fresh()->response_code);
    }

    public function test_the_endpoint_switches_off_after_the_configured_number_of_failed_events(): void
    {
        config(['swayy.webhooks.tries' => 1, 'swayy.webhooks.disable_after' => 2]);
        Http::fake(['*' => Http::response('kaputt', 500)]);
        [$endpoint, $first] = $this->endpointWithDelivery('https://hooks.example.test/swayy');
        $second = $this->delivery($endpoint);

        (new DeliverWebhook($first->id))->withFakeQueueInteractions()->handle();

        $this->assertTrue($endpoint->fresh()->is_active);
        $this->assertSame(1, $endpoint->fresh()->failure_count);

        (new DeliverWebhook($second->id))->withFakeQueueInteractions()->handle();

        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertNotNull($endpoint->fresh()->disabled_at);
    }

    public function test_the_log_keeps_as_much_of_the_answer_as_configured(): void
    {
        config(['swayy.webhooks.response_limit' => 5]);
        Http::fake(['*' => Http::response('äöüäöüäöü', 500)]);
        [, $delivery] = $this->endpointWithDelivery('https://hooks.example.test/swayy');

        (new DeliverWebhook($delivery->id))->withFakeQueueInteractions()->handle();

        $this->assertSame('äöüäö', $delivery->fresh()->response_body);
    }

    public function test_the_settings_are_read_from_the_environment(): void
    {
        $env = [
            'SWAYY_WEBHOOK_TIMEOUT' => '7',
            'SWAYY_WEBHOOK_TRIES' => '4',
            'SWAYY_WEBHOOK_BACKOFF' => '15, 45',
            'SWAYY_WEBHOOK_DISABLE_AFTER' => '9',
            'SWAYY_WEBHOOK_RESPONSE_LIMIT' => '300',
            'SWAYY_WEBHOOK_LOG_ENTRIES' => '40',
        ];
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $config = require config_path('swayy.php');
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }

        $this->assertSame([
            'timeout' => 7,
            'tries' => 4,
            'backoff' => [15, 45],
            'disable_after' => 9,
            'response_limit' => 300,
            'log_entries' => 40,
        ], $config['webhooks']);
    }

    public function test_the_webhook_page_describes_the_configured_behaviour(): void
    {
        config([
            'swayy.webhooks.tries' => 3,
            'swayy.webhooks.backoff' => [90, 3600],
            'swayy.webhooks.disable_after' => 7,
        ]);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/webhooks')
            ->assertOk()
            ->assertSee('Fehlversuche werden wiederholt: 3 Versuche je Ereignis, Abstände von 90 Sek. bis 1 Std. Ein Servername')
            ->assertSee('Nach 7 gescheiterten Ereignissen in Folge schaltet sich der Endpunkt selbst ab');
    }

    public function test_equal_delays_and_a_single_try_read_naturally(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        config(['swayy.webhooks.tries' => 4, 'swayy.webhooks.backoff' => [600]]);
        $this->actingAs($admin)->get('/admin/webhooks')
            ->assertSee('Fehlversuche werden wiederholt: 4 Versuche je Ereignis, Abstände je 10 Min. Ein Servername');

        config(['swayy.webhooks.tries' => 1]);
        $this->actingAs($admin)->get('/admin/webhooks')
            ->assertSee('Jedes Ereignis bekommt einen Zustellversuch. Ein Servername');
    }

    public function test_the_delivery_log_shows_as_many_entries_as_configured(): void
    {
        config(['swayy.webhooks.log_entries' => 2]);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        [$endpoint] = $this->endpointWithDelivery('https://hooks.example.test/swayy', $setup['tenant']->id);
        $this->delivery($endpoint);
        $this->delivery($endpoint);
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->get('/admin/webhooks')
            ->assertOk()
            ->assertSee('Die letzten 2 Versuche.');

        // Jede Zeile des Protokolls nennt das Ziel in einer eigenen Zelle.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'truncate text-xs text-stone-500">https://hooks.example.test/swayy</td>'));
    }

    public function test_the_delivery_log_shows_why_nothing_was_sent(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        [$endpoint] = $this->endpointWithDelivery('https://hooks.example.test/swayy', $setup['tenant']->id);
        WebhookDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created'],
            'status' => 'failed',
            'response_body' => 'Nicht gesendet: Der Endpunkt ist pausiert. Unter „Webhooks“ lässt er sich wieder aktivieren.',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/webhooks')
            ->assertOk()
            ->assertSee('Der Endpunkt ist pausiert. Unter „Webhooks“ lässt er sich wieder aktivieren.');
    }

    /**
     * @return array{WebhookEndpoint, WebhookDelivery}
     */
    private function endpointWithDelivery(string $url, ?int $tenantId = null): array
    {
        $tenantId ??= $this->createTenantSetup()['tenant']->id;

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'url' => $url,
            'secret' => 'geheim',
            'events' => ['*'],
            'is_active' => true,
        ]);

        return [$endpoint, $this->delivery($endpoint)];
    }

    private function delivery(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return WebhookDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created'],
            'status' => 'pending',
        ]);
    }

    /**
     * Antwortet fuer jede Anfrage gleich und merkt sich die Optionen, mit
     * denen der Client sie abgeschickt haette.
     */
    private function fakeReceiver(mixed $response): void
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Sms\SevenIoProvider;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_normalization_for_german_market(): void
    {
        $sms = new SmsManager;

        $this->assertSame('491701234567', $sms->normalizePhone('0170 1234567'));
        $this->assertSame('491701234567', $sms->normalizePhone('+49 170 1234567'));
        $this->assertSame('491701234567', $sms->normalizePhone('0049 170 1234567'));
        $this->assertSame('491701234567', $sms->normalizePhone('491701234567'));
        $this->assertNull($sms->normalizePhone(null));
        $this->assertNull($sms->normalizePhone('keine nummer'));
    }

    public function test_sevenio_provider_send_success(): void
    {
        Http::fake([
            'gateway.seven.io/api/sms' => Http::response(['success' => '100', 'messages' => []], 200),
        ]);

        $provider = new SevenIoProvider('test-key', 'Salon');
        $this->assertTrue($provider->send('491701234567', 'Test'));

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Api-Key', 'test-key')
                && $request['to'] === '491701234567'
                && $request['from'] === 'Salon';
        });
    }

    public function test_sevenio_provider_send_rejected(): void
    {
        Http::fake([
            'gateway.seven.io/api/sms' => Http::response(['success' => '305'], 200),
        ]);

        $provider = new SevenIoProvider('test-key', 'Salon');
        $this->assertFalse($provider->send('491701234567', 'Test'));
    }

    public function test_sevenio_test_connection_via_balance(): void
    {
        Http::fake([
            'gateway.seven.io/api/balance' => Http::response('12.345', 200),
        ]);

        $provider = new SevenIoProvider('test-key', '');
        $this->assertTrue($provider->testConnection());
    }

    public function test_manager_resolves_configured_provider(): void
    {
        $tenant = Tenant::factory()->create();

        IntegrationConnection::create([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'sevenio',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'secret-key',
                'sender_id' => 'Salon',
            ])),
        ]);

        $sms = new SmsManager;
        $this->assertTrue($sms->isConfigured($tenant));
        $this->assertInstanceOf(SevenIoProvider::class, $sms->providerFor($tenant));
    }

    public function test_manager_returns_null_without_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $sms = new SmsManager;

        $this->assertFalse($sms->isConfigured($tenant));
        $this->assertNull($sms->providerFor($tenant));
    }
}

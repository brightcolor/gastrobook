<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NewsletterSyncTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function connectMailwizz(int $tenantId): void
    {
        IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'provider' => 'mailwizz',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'api_url' => 'https://news.example.test/api',
                'api_key' => 'test-key',
                'list_uid' => 'ab12cd34',
            ])),
        ]);
    }

    public function test_online_booking_with_newsletter_consent_pushes_guest_to_mailwizz(): void
    {
        Mail::fake();
        Http::fake([
            'news.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectMailwizz($setup['tenant']->id);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Nadine Newsletter',
            'email' => 'nadine@example.test',
            'phone' => '+49 170 8888888',
            'privacy_accepted' => '1',
            'newsletter' => '1',
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/lists/ab12cd34/subscribers')
                && $request->hasHeader('X-API-KEY', 'test-key')
                && $request['EMAIL'] === 'nadine@example.test';
        });

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'newsletter',
            'recipient' => 'nadine@example.test',
            'status' => 'sent',
        ]);
    }

    public function test_booking_without_newsletter_consent_does_not_contact_mailwizz(): void
    {
        Mail::fake();
        Http::fake();

        $setup = $this->createTenantSetup();
        $this->connectMailwizz($setup['tenant']->id);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Ohne Newsletter',
            'email' => 'ohne@example.test',
            'phone' => '+49 170 7777777',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_sync_is_skipped_when_no_integration_is_configured(): void
    {
        Mail::fake();
        Http::fake();

        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Kein Mailwizz',
            'email' => 'kein@example.test',
            'phone' => '+49 170 6666666',
            'privacy_accepted' => '1',
            'newsletter' => '1',
        ])->assertRedirect();

        Http::assertNothingSent();
        // Consent is still recorded — sync can be triggered once the integration exists
        $guest = Guest::withoutGlobalScopes()->where('email', 'kein@example.test')->first();
        $this->assertTrue($guest->marketing_consent);
    }

    public function test_admin_can_configure_mailwizz_with_connection_test(): void
    {
        Http::fake([
            'news.example.test/*' => Http::response(['status' => 'success'], 200),
        ]);

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/mailwizz', [
            'api_url' => 'https://news.example.test/api',
            'api_key' => 'secret-key',
            'list_uid' => 'xy99zz88',
            'enabled' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $connection = IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('provider', 'mailwizz')
            ->first();

        $this->assertSame('connected', $connection->status);

        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        $this->assertSame('secret-key', $credentials['api_key']);
    }

    public function test_staff_cannot_configure_integrations(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->put('/admin/settings/mailwizz', [
            'api_url' => 'https://news.example.test/api',
            'api_key' => 'x',
            'list_uid' => 'y',
        ])->assertForbidden();
    }
}

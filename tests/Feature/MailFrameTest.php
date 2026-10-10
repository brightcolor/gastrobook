<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailFrameTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $tenant
     * @return array{0: Tenant, 1: Location}
     */
    private function luna(array $location = [], array $tenant = [], string $address = 'du'): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(array_merge([
            'name' => 'Luna GmbH', 'brand_primary_color' => null, 'brand_logo_path' => null,
            'imprint_url' => 'https://luna.example/impressum', 'privacy_url' => 'https://luna.example/datenschutz',
        ], $tenant));
        $setup['location']->update(array_merge([
            'name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115',
            'city' => 'Berlin', 'phone' => '030 1234567', 'email' => 'luna@example.test', 'brand_logo_path' => null,
        ], $location));
        $setup['location']->settings()->update(['guest_address' => $address]);
        $this->clearTenantContext();

        return [$setup['tenant']->fresh(), $setup['location']->fresh()];
    }

    private function frames(): MailFrameFactory
    {
        return app(MailFrameFactory::class);
    }

    public function test_guest_frame_uses_location_data_and_headline_in_du_and_sie(): void
    {
        [$tenant, $location] = $this->luna(address: 'du');
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);

        $this->assertSame(MailFrame::LOOK_TENANT, $frame->look);
        $this->assertTrue($frame->du);
        $this->assertSame('Deine Reservierung ist bestätigt', $frame->headline);
        $this->assertSame('Trattoria Luna', $frame->wordmark);
        $this->assertSame('Beispielstraße 12', $frame->footer['street']);
        $this->assertSame('10115 Berlin', $frame->footer['city']);
        $this->assertSame('030 1234567', $frame->footer['phone']);
        $this->assertSame('luna@example.test', $frame->footer['email']);
        $this->assertSame('https://luna.example/impressum', $frame->footer['imprint']);
        $this->assertSame('Du bekommst diese Mail, weil du bei Trattoria Luna reserviert hast.', $frame->reason);
        $this->assertTrue($frame->poweredBy);

        [$tenant, $location] = $this->luna(address: 'Sie');
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);
        $this->assertSame('Ihre Reservierung ist bestätigt', $frame->headline);
        $this->assertStringContainsString('reserviert haben', $frame->reason);
    }

    public function test_missing_or_unsafe_footer_data_is_left_out(): void
    {
        [$tenant, $location] = $this->luna(
            ['address_line1' => null, 'postal_code' => null, 'city' => null, 'phone' => null, 'email' => 'keine-adresse'],
            ['imprint_url' => 'javascript:alert(1)', 'privacy_url' => null],
        );
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);

        $this->assertNull($frame->footer['street']);
        $this->assertNull($frame->footer['city']);
        $this->assertNull($frame->footer['phone']);
        $this->assertNull($frame->footer['email']);
        $this->assertNull($frame->footer['imprint']);
        $this->assertNull($frame->footer['privacy']);
        $this->assertSame('Trattoria Luna', $frame->footer['name']);
    }

    public function test_brand_color_comes_from_the_tenant(): void
    {
        [$tenant, $location] = $this->luna(tenant: ['brand_primary_color' => '#b91c1c']);
        $this->assertSame('#b91c1c', $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->brandColor);

        [$tenant, $location] = $this->luna();
        $this->assertSame('#0f766e', $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->brandColor);
    }

    public function test_logo_location_first_then_tenant_and_only_supported_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/standort.png', UploadedFile::fake()->image('standort.png', 400, 100)->getContent());
        Storage::disk('public')->put('logos/1/betrieb.png', UploadedFile::fake()->image('betrieb.png', 100, 100)->getContent());
        Storage::disk('public')->put('logos/1/logo.webp', 'webp');
        Storage::disk('public')->put('logos/1/logo.svg', '<svg/>');
        Storage::disk('public')->put('logos/1/gross.png', str_repeat('x', 300 * 1024));

        [$tenant, $location] = $this->luna(['brand_logo_path' => 'logos/1/standort.png'], ['brand_logo_path' => 'logos/1/betrieb.png']);
        $frame = $this->frames()->forGuest('reservation_confirmed', $tenant, $location);
        $this->assertSame(Storage::disk('public')->path('logos/1/standort.png'), $frame->logoPath);
        $this->assertSame(44, $frame->logoHeight);
        $this->assertSame(176, $frame->logoWidth);

        [$tenant, $location] = $this->luna(['brand_logo_path' => null], ['brand_logo_path' => 'logos/1/betrieb.png']);
        $this->assertSame(Storage::disk('public')->path('logos/1/betrieb.png'), $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoPath);

        foreach (['logos/1/logo.webp', 'logos/1/logo.svg', 'logos/1/gross.png', 'logos/1/fehlt.png'] as $path) {
            [$tenant, $location] = $this->luna(['brand_logo_path' => $path]);
            $this->assertNull($this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoPath, $path);
        }

        config(['swayy.mail.logo_height' => 30]);
        [$tenant, $location] = $this->luna(['brand_logo_path' => 'logos/1/standort.png']);
        $this->assertSame(120, $this->frames()->forGuest('reservation_confirmed', $tenant, $location)->logoWidth);
    }

    public function test_swayy_frame_follows_the_owner_address_setting(): void
    {
        config(['swayy.mail.owner_address' => 'du']);
        $frame = $this->frames()->forSwayy('swayy_trial_expiry');
        $this->assertSame(MailFrame::LOOK_SWAYY, $frame->look);
        $this->assertSame('Deine Testphase endet bald', $frame->headline);
        $this->assertSame(route('contact'), $frame->footer['help']);
        $this->assertSame(route('legal.imprint'), $frame->footer['imprint']);

        config(['swayy.mail.owner_address' => 'Sie']);
        $this->assertSame('Ihre Testphase endet bald', $this->frames()->forSwayy('swayy_trial_expiry')->headline);
    }

    public function test_unknown_type_has_no_headline_and_the_guest_reason(): void
    {
        [$tenant, $location] = $this->luna();
        $frame = $this->frames()->forGuest('etwas_neues', $tenant, $location);

        $this->assertNull($frame->headline);
        $this->assertStringContainsString('reserviert hast', $frame->reason);
    }

    public function test_operator_and_notice_texts_use_their_placeholders(): void
    {
        $frame = $this->frames()->forSwayy('operator_new_reservation', ['location' => 'Trattoria Luna']);
        $this->assertSame('Neue Reservierung für Trattoria Luna', $frame->headline);
        $this->assertStringContainsString('für Trattoria Luna Benachrichtigungen', $frame->reason);

        $this->assertSame('Lastschrift gekündigt – Luna GmbH', $this->frames()->forSwayy('swayy_notice', ['subject' => 'Lastschrift gekündigt – Luna GmbH'])->headline);
    }

    public function test_button_hosts_are_the_app_the_current_host_and_the_panel(): void
    {
        config(['app.url' => 'https://swayy.example', 'provisioning.panel_base_url' => 'https://panel.example']);
        $hosts = MailFrameFactory::buttonHosts();

        $this->assertContains('swayy.example', $hosts);
        $this->assertContains('panel.example', $hosts);
        $this->assertContains((string) parse_url(url('/'), PHP_URL_HOST), $hosts);
        $this->assertSame($hosts, array_values(array_unique($hosts)));
    }
}

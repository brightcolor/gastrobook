<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\NotificationTemplate;
use App\Models\Reservation;
use App\Services\NotificationTemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestAddressTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function reservation(array $setup): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Gast', 'guest_email_snapshot' => 'g@example.test',
        ]);
    }

    public function test_default_is_formal_sie(): void
    {
        $setup = $this->createTenantSetup();
        $r = $this->reservation($setup);

        $out = app(NotificationTemplateRenderer::class)->render('reservation_confirmed', $r);

        $this->assertStringContainsString('Ihre Reservierung ist bestätigt', $out['body']);
        $this->assertStringNotContainsString('deine Reservierung', $out['body']);
    }

    public function test_informal_du_when_configured(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['guest_address' => 'du']);
        $r = $this->reservation($setup);

        $out = app(NotificationTemplateRenderer::class)->render('reservation_confirmed', $r);

        $this->assertStringContainsString('deine Reservierung ist bestätigt', $out['body']);
        $this->assertStringContainsString('auf deinen Besuch', $out['body']);
        $this->assertStringNotContainsString('Ihre Reservierung', $out['body']);
    }

    public function test_custom_template_overrides_address(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['guest_address' => 'du']);
        NotificationTemplate::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'key' => 'reservation_confirmed',
            'locale' => 'de', 'is_active' => true, 'subject' => 'Custom', 'body' => 'Eigener Text {guest_name}',
        ]);
        $r = $this->reservation($setup);

        $out = app(NotificationTemplateRenderer::class)->render('reservation_confirmed', $r);

        $this->assertStringContainsString('Eigener Text Gast', $out['body']);
        $this->assertStringNotContainsString('deine Reservierung', $out['body']);
    }

    public function test_defaults_helper_returns_du_variant(): void
    {
        $du = NotificationTemplateRenderer::defaults('du');
        $sie = NotificationTemplateRenderer::defaults();

        $this->assertStringContainsString('deine Reservierung', $du['reservation_confirmed']['body']);
        $this->assertStringContainsString('Ihre Reservierung', $sie['reservation_confirmed']['body']);
    }
}

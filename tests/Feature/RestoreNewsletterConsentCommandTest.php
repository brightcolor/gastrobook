<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * swayy:newsletter-consent-restore holt Einwilligungen zurück, die vor 1.136.0
 * nur eine spätere Online-Buchung ohne Haken widerrufen hatte.
 */
class RestoreNewsletterConsentCommandTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  list<array{0: string, 1: bool, 2: string}>  $history  [Typ, erteilt, Kanal], älteste zuerst
     */
    private function guest(Tenant $tenant, array $history, array $data = []): Guest
    {
        $last = end($history);
        $guest = Guest::withoutGlobalScopes()->create($data + [
            'tenant_id' => $tenant->id,
            'first_name' => 'Ida',
            'last_name' => 'Kessler',
            'email' => 'kessler'.uniqid().'@example.test',
            'marketing_consent' => $last !== false && $last[1],
        ]);
        foreach ($history as $i => [$type, $granted, $channel]) {
            GuestConsent::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'guest_id' => $guest->id, 'type' => $type,
                'granted' => $granted, 'channel' => $channel, 'recorded_at' => now()->subDays(30 - $i),
            ]);
        }

        return $guest;
    }

    public function test_only_withdrawals_by_a_booking_without_the_box_come_back(): void
    {
        $setup = $this->createTenantSetup();
        $tenant = $setup['tenant'];
        $affected = $this->guest($tenant, [['newsletter', true, 'booking_widget'], ['newsletter', false, 'booking_widget']]);
        $unsubscribed = $this->guest($tenant, [['newsletter', true, 'booking_widget'], ['newsletter', false, 'booking_widget'], ['marketing', false, 'email_link']]);
        $neverGranted = $this->guest($tenant, [['newsletter', false, 'booking_widget']]);
        $byStaff = $this->guest($tenant, [['newsletter', true, 'booking_widget'], ['newsletter', false, 'staff']]);
        $anonymized = $this->guest($tenant, [['newsletter', true, 'booking_widget'], ['newsletter', false, 'booking_widget']], ['anonymized' => true]);
        $this->clearTenantContext();

        $this->artisan('swayy:newsletter-consent-restore')
            ->expectsOutputToContain('Gäste #'.$affected->id)
            ->expectsOutputToContain('Mit --apply werden sie wiederhergestellt.')
            ->assertSuccessful();
        $this->assertFalse($affected->fresh()->marketing_consent, 'Ohne --apply ändert der Befehl nichts.');

        $this->artisan('swayy:newsletter-consent-restore', ['--apply' => true])
            ->expectsOutputToContain('1 Einwilligung(en) wiederhergestellt')
            ->assertSuccessful();

        $this->assertTrue($affected->fresh()->marketing_consent);
        foreach ([$unsubscribed, $neverGranted, $byStaff, $anonymized] as $guest) {
            $this->assertFalse($guest->fresh()->marketing_consent, "Gast #{$guest->id} wurde wiederhergestellt.");
        }

        $entry = GuestConsent::withoutGlobalScopes()->where('guest_id', $affected->id)->orderByDesc('id')->first();
        $this->assertSame(['newsletter', true, 'correction'], [$entry->type, (bool) $entry->granted, $entry->channel]);
        $log = AuditLog::withoutGlobalScopes()->where('action', 'guest.newsletter_consent_restored')->sole();
        $this->assertSame($tenant->id, $log->tenant_id);

        // Ein zweiter Lauf findet nichts mehr.
        $this->artisan('swayy:newsletter-consent-restore', ['--apply' => true])
            ->expectsOutputToContain('Keine Einwilligung zum Wiederherstellen gefunden.')
            ->assertSuccessful();
    }

    public function test_a_business_that_counts_the_empty_box_stays_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['newsletter_unticked_revokes' => true]);
        $guest = $this->guest($setup['tenant'], [['newsletter', true, 'booking_widget'], ['newsletter', false, 'booking_widget']]);
        $this->clearTenantContext();

        $this->artisan('swayy:newsletter-consent-restore', ['--apply' => true])->assertSuccessful();

        $this->assertFalse($guest->fresh()->marketing_consent);
    }

    public function test_an_unknown_business_gets_the_way_to_the_list(): void
    {
        $this->artisan('swayy:newsletter-consent-restore', ['--tenant' => 'gibt-es-nicht'])
            ->expectsOutputToContain('php artisan swayy:tenants')
            ->assertFailed();
    }
}

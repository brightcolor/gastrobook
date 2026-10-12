<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Das Protokoll spricht in der Sprache des Betriebs: Ein Salon liest „Termin“
 * und „Kunde“, ein Restaurant „Reservierung“ und „Gast“.
 */
class AuditSalonWordsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function log(int $tenantId, string $action): void
    {
        AuditLog::create(['tenant_id' => $tenantId, 'action' => $action, 'created_at' => now()]);
    }

    public function test_actions_read_in_the_words_of_the_business_type(): void
    {
        $this->assertSame('Reservierung: Status geändert', AuditLog::labelFor('reservation.status_changed'));
        $this->assertSame('Termin: Status geändert', AuditLog::labelFor('reservation.status_changed', salon: true));
        $this->assertSame('Termin umgebucht', AuditLog::labelFor('reservation.rescheduled', salon: true));
        $this->assertSame('Termin: Person zugewiesen', AuditLog::labelFor('reservation.staff_assigned', salon: true));
        // Zusammengesetzt aus Bereich und Tätigkeit:
        $this->assertSame('Reservierung storniert', AuditLog::labelFor('reservation.cancelled'));
        $this->assertSame('Termin storniert', AuditLog::labelFor('reservation.cancelled', salon: true));
        $this->assertSame('Gast geändert', AuditLog::labelFor('guest.updated'));
        $this->assertSame('Kunde geändert', AuditLog::labelFor('guest.updated', salon: true));
        $this->assertSame('Gäste zusammengeführt', AuditLog::labelFor('guest.merged'));
        $this->assertSame('Kunden zusammengeführt', AuditLog::labelFor('guest.merged', salon: true));
        $this->assertSame('Absender für Kundenmails geändert', AuditLog::labelFor('tenant.guest_mail_updated', salon: true));
        // Was beide Betriebsarten gleich nennen, bleibt gleich.
        $this->assertSame('Standort: Buchungsregeln geändert', AuditLog::labelFor('location.booking_rules_updated', salon: true));
        $this->assertSame('Zahlung: Aktion payment.foo_bar', AuditLog::labelFor('payment.foo_bar', salon: true));
    }

    public function test_areas_read_in_the_words_of_the_business_type(): void
    {
        $this->assertSame('Reservierung', AuditLog::areaLabel('reservation.created'));
        $this->assertSame('Termin', AuditLog::areaLabel('reservation.created', salon: true));
        $this->assertSame('Kunde', AuditLog::areaLabel('guest.updated', salon: true));
        $this->assertSame('Kunden', AuditLog::areaLabel('guests.exported', salon: true));
        $this->assertSame('Standort', AuditLog::areaLabel('location.updated', salon: true));
        $this->assertNull(AuditLog::areaLabel('zzz.created', salon: true));
        $this->assertSame('Termin', (new AuditLog(['action' => 'reservation.created']))->area(true));
        $this->assertSame('Termin: Status geändert', (new AuditLog(['action' => 'reservation.status_changed']))->actionLabel(true));
        $this->assertSame('Reservierung: Status geändert', (new AuditLog(['action' => 'reservation.status_changed']))->actionLabel());
    }

    public function test_the_salon_words_replace_only_what_exists_and_leave_no_restaurant_word_behind(): void
    {
        foreach (array_keys(AuditLog::SALON_SUBJECTS) as $prefix) {
            $this->assertArrayHasKey($prefix, AuditLog::SUBJECTS, "{$prefix} ist kein Bereich.");
        }
        foreach (AuditLog::SALON_ACTION_LABELS as $action => $label) {
            $this->assertArrayHasKey($action, AuditLog::ACTION_LABELS, "{$action} hat keine Bezeichnung für Restaurants.");
            $this->assertDoesNotMatchRegularExpression('/Reservierung|Gast|Gäste/u', $label, "{$action}: {$label}");
        }
        // Jede Bezeichnung, die im Restaurant „Reservierung“ oder „Gast“ sagt, hat eine Fassung für den Salon.
        foreach (AuditLog::ACTION_LABELS as $action => $label) {
            if (preg_match('/Reservierung|\bGast\b|Gäste|Gastdaten/u', $label) === 1 && ! str_starts_with($action, 'event.') && $action !== 'payment.forfeited') {
                $this->assertArrayHasKey($action, AuditLog::SALON_ACTION_LABELS, "{$action} sagt im Salon noch: {$label}");
            }
        }
    }

    public function test_the_page_and_the_area_filter_of_a_salon_read_in_its_words(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed');
        $this->log($setup['tenant']->id, 'guest.updated');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/audit')->assertOk();

        $response->assertSee('Termin: Status geändert')
            ->assertSee('Kunde geändert')
            ->assertDontSee('Reservierung: Status geändert');
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('#<option value="reservation"[^>]*>Termin</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="guest"[^>]*>Kunde</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="guests"[^>]*>Kunden</option>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="reservation"[^>]*>Reservierung</option>#', $html);

        $this->actingAs($owner)->get('/admin/audit?area=reservation')
            ->assertOk()
            ->assertSee('Termin: Status geändert')
            ->assertDontSee('Kunde geändert')
            ->assertSee('Bereich')
            ->assertSee('Termin');
    }

    public function test_the_page_and_the_area_filter_of_a_restaurant_keep_their_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Reservierung: Status geändert')
            ->assertDontSee('Termin: Status geändert');
        $this->assertMatchesRegularExpression('#<option value="reservation"[^>]*>Reservierung</option>#', (string) $response->getContent());
    }

    public function test_the_search_finds_actions_by_the_words_of_the_business_type(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed');
        $this->log($setup['tenant']->id, 'guest.updated');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q=Termin')
            ->assertOk()
            ->assertSee('Termin: Status geändert')
            ->assertDontSee('Kunde geändert');
        // Wer weiter „Reservierung“ tippt, findet dieselben Einträge.
        $this->actingAs($owner)->get('/admin/audit?q=Reservierung')
            ->assertOk()
            ->assertSee('Termin: Status geändert')
            ->assertDontSee('Kunde geändert');
    }
}

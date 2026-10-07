<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Große Gruppen oberhalb der online buchbaren Runde bekommen auf der
 * Buchungsseite einen warmen Hinweis: ein "Mehr"-Knopf hinter der größten
 * Personenzahl deckt eine E-Mail-Einladung auf. Die Adresse ist eine
 * Einstellung je Standort - leer blendet den Knopf aus.
 */
class LargeGroupNoticeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function buchungsUrl(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
    }

    public function test_the_more_button_and_the_email_notice_appear_when_configured(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_party_online' => 2,
            'max_party_online' => 10,
            'large_group_email' => 'gruppen@example.test',
            'guest_address' => 'Sie',
        ]);
        $this->clearTenantContext();

        $this->get($this->buchungsUrl($setup))
            ->assertOk()
            ->assertSee('id="partyMoreBtn"', false)
            ->assertSee('id="largeGroupNotice"', false)
            ->assertSee('Große Runde? Wie schön!', false)
            // Schwelle = letzte wählbare Personenzahl + 1.
            ->assertSee('ab 11 Personen', false)
            ->assertSee('mailto:gruppen@example.test', false)
            ->assertSee('Schreiben Sie uns kurz', false);
    }

    /**
     * Die Personen-Buttons kappen bei min + 8, auch wenn der Betrieb hinten
     * mehr zulässt (etwa 30 für Kombinationen über die interne Maske). Der
     * Hinweis nennt die Zahl hinter dem letzten Knopf, nicht das rohe Maximum.
     */
    public function test_the_threshold_follows_the_last_selectable_button(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_party_online' => 2,
            'max_party_online' => 30,
            'large_group_email' => 'gruppen@example.test',
        ]);
        $this->clearTenantContext();

        $this->get($this->buchungsUrl($setup))
            ->assertOk()
            ->assertSee('ab 11 Personen', false)
            ->assertDontSee('ab 31', false);
    }

    public function test_neither_appears_without_an_address(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get($this->buchungsUrl($setup))
            ->assertOk()
            ->assertDontSee('id="partyMoreBtn"', false)
            ->assertDontSee('id="largeGroupNotice"', false);
    }

    public function test_the_notice_follows_the_informal_tone(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'large_group_email' => 'gruppen@example.test',
            'guest_address' => 'du',
        ]);
        $this->clearTenantContext();

        $this->get($this->buchungsUrl($setup))
            ->assertOk()
            ->assertSee('Schreib uns kurz', false)
            ->assertDontSee('Schreiben Sie uns kurz', false);
    }

    public function test_the_booking_rules_form_saves_a_large_group_address(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->from('/admin/settings')
            ->put('/admin/settings/booking-rules', $this->bookingRules([
                'large_group_email' => 'gruppen@example.test',
            ]))
            ->assertRedirect('/admin/settings')
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'gruppen@example.test',
            $setup['location']->settings()->sole()->large_group_email
        );
    }

    public function test_the_booking_rules_form_rejects_an_invalid_large_group_address(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->putJson('/admin/settings/booking-rules', $this->bookingRules([
                'large_group_email' => 'keine-adresse',
            ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.large_group_email.0',
                'E-Mail für große Gruppen muss eine gültige E-Mail-Adresse sein (z. B. name@beispiel.de).'
            );
    }

    /**
     * Gültige Buchungsregeln; $overrides setzt einzelne Felder.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookingRules(array $overrides = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 30,
            'default_duration_minutes' => 120,
            'buffer_minutes' => 0,
            'min_lead_minutes' => 0,
            'max_advance_days' => 90,
            'min_party_online' => 1,
            'max_party_online' => 10,
            'booking_confirmation_mode' => 'auto',
            'capacity_mode' => 'table',
            'cancellation_deadline_minutes' => 120,
            'modification_deadline_minutes' => 120,
            'reminder_hours_before' => 24,
            'refund_mode' => 'off',
            'refund_percent' => 0,
            'refund_processing' => 'immediate',
            'guest_address' => 'Sie',
            'feedback_hours_after' => 18,
            'feedback_redirect_min_score' => 4,
        ], $overrides);
    }
}

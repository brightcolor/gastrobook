<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegalDocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Solange Impressum oder AGB noch die Vorlage enthalten, weist die Plattform
 * Besucher darauf hin, dass hier nichts in Betrieb ist.
 */
class PlatformNoticeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const HINWEIS = 'Diese Plattform ist noch nicht in Betrieb.';

    /**
     * Alle drei Rechtstexte mit echten Angaben belegen.
     */
    private function fillInAllDocuments(): void
    {
        foreach (['impressum', 'datenschutz', 'agb'] as $key) {
            Storage::disk('local')->put("legal/{$key}.md", "# Titel\n\nEchter Text der Beispiel AG, Hafenweg 3, 23552 Lübeck.");
        }
    }

    public function test_the_landing_page_says_the_platform_is_not_live_yet(): void
    {
        Storage::fake('local'); // nichts hinterlegt -> es gilt die Vorlage

        $this->get('/')
            ->assertOk()
            ->assertSee(self::HINWEIS);
    }

    public function test_the_notice_names_the_documents_that_are_still_open(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('legal/datenschutz.md', "# Datenschutz\n\nEchter Text der Beispiel AG.");

        // Impressum und AGB stehen noch auf Vorlage, der Datenschutz nicht.
        $this->get('/')
            ->assertOk()
            ->assertSee('Impressum und AGB')
            ->assertDontSee('Datenschutzerklärung und');
    }

    public function test_the_notice_is_gone_once_the_documents_are_filled_in(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();

        $this->get('/')->assertOk()->assertDontSee(self::HINWEIS);
        $this->get('/impressum')->assertOk()->assertDontSee(self::HINWEIS);
        $this->get('/login')->assertOk()->assertDontSee('Noch nicht in Betrieb');
    }

    public function test_the_legal_pages_carry_the_notice_too(): void
    {
        Storage::fake('local');

        $this->get('/impressum')->assertOk()->assertSee(self::HINWEIS);
        $this->get('/agb')->assertOk()->assertSee(self::HINWEIS);
    }

    public function test_the_sign_up_pages_carry_a_short_version(): void
    {
        Storage::fake('local');

        $this->get('/login')->assertOk()->assertSee('Noch nicht in Betrieb');
        $this->get('/register')->assertOk()->assertSee('Noch nicht in Betrieb');
    }

    /**
     * Der wichtigste Fall: Auf der Buchungsseite eines Betriebs tritt der
     * Betrieb als Anbieter auf, nicht die Plattform. Dort darf der Hinweis
     * niemals erscheinen – er würde echte Gäste verschrecken.
     */
    public function test_a_guest_booking_page_never_shows_the_notice(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug)
            ->assertOk()
            ->assertDontSee(self::HINWEIS)
            ->assertDontSee('Noch nicht in Betrieb');
    }

    /**
     * Das Layout allein schützt nicht: Verlinkt die Buchungsseite auf die
     * Rechtstexte der Plattform, landet der Gast mit einem Klick doch im
     * Marketing-Layout – und liest dort „keine echten Daten eingeben", während
     * er sie gerade eingibt. Gästeseiten dürfen nur auf die Rechtstexte des
     * Betriebs verweisen.
     */
    public function test_a_guest_booking_page_does_not_link_to_the_platform_legal_pages(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $antwort = $this->get('/book/'.$setup['tenant']->slug)->assertOk();

        foreach (['legal.privacy', 'legal.imprint', 'legal.terms'] as $route) {
            $antwort->assertDontSee('href="'.route($route).'"', false);
        }
    }

    public function test_the_platform_dashboard_tells_the_operator_what_to_do(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['saas_role' => 'super_admin']);

        $this->actingAs($admin)
            ->get('/saas')
            ->assertOk()
            ->assertSee('nicht in Betrieb')
            ->assertSee('impressum.md');
    }

    // ── Die Erkennung selbst ──────────────────────────────────────────────

    public function test_the_shipped_templates_count_as_unfilled(): void
    {
        Storage::fake('local');

        $status = new LegalDocumentStatus;

        $this->assertFalse($status->isReady());
        $this->assertSame(['impressum', 'datenschutz', 'agb'], array_keys($status->pending()));
    }

    public function test_real_content_counts_as_filled_in(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();

        $this->assertTrue((new LegalDocumentStatus)->isReady());
    }

    /**
     * In der AGB gibt es keine Musterdaten, nur den Warnkasten am Kopf. Ihn zu
     * entfernen ist deshalb das bewusste "geprüft und übernommen" – der
     * Rumpftext selbst ist bereits eine vollstaendige AGB.
     */
    public function test_removing_the_warning_block_adopts_the_terms(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();

        $vorlage = (string) file_get_contents(resource_path('legal/agb.md'));
        Storage::disk('local')->put('legal/agb.md', (string) preg_replace('/^> .*$\n?/m', '', $vorlage));

        $this->assertTrue((new LegalDocumentStatus)->isReady());
    }

    /**
     * Beim Impressum reicht das ausdruecklich NICHT: Dort stehen Musterdaten,
     * die ein echtes Impressum niemals enthalten darf.
     */
    public function test_removing_the_warning_block_is_not_enough_for_the_imprint(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();

        $vorlage = (string) file_get_contents(resource_path('legal/impressum.md'));
        Storage::disk('local')->put('legal/impressum.md', (string) preg_replace('/^> .*$\n?/m', '', $vorlage));

        $status = new LegalDocumentStatus;
        $this->assertFalse($status->isReady());
        $this->assertSame(['impressum'], array_keys($status->pending()));
    }

    public function test_an_empty_file_counts_as_unfilled(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();
        Storage::disk('local')->put('legal/impressum.md', "   \n\n");

        $this->assertSame(['impressum'], array_keys((new LegalDocumentStatus)->pending()));
    }

    /**
     * Die Datenschutzerklaerung enthaelt Klammern, die eine Entscheidung
     * markieren ([Stripe] streichen oder stehenlassen). Die duerfen den
     * Hinweis nicht dauerhaft festnageln – nur die Betreiberangaben zaehlen.
     */
    public function test_optional_brackets_do_not_keep_the_notice_alive(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();
        Storage::disk('local')->put(
            'legal/datenschutz.md',
            "# Datenschutz\n\nZahlungen über [Stripe] und/oder [PayPal]. Aufbewahrung [36] Monate."
        );

        $this->assertTrue((new LegalDocumentStatus)->isReady());
    }

    /**
     * Der Warnkasten der Datenschutzerklärung läuft in der Vorlage über zwei
     * Zeilen. Ohne Einebnung der Umbrüche träfe der Marker nie – und der
     * Hinweis verschwände, während die Seite den Besuchern weiter „Bitte
     * ergänzen Sie die in eckigen Klammern markierten Angaben" zeigt.
     */
    public function test_the_wrapped_warning_block_in_the_privacy_template_is_detected(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();

        // Alle Betreiberangaben gepflegt – der umbrochene Warnkasten am Kopf
        // ist damit der einzige verbliebene Marker.
        Storage::disk('local')->put('legal/datenschutz.md', implode("\n", [
            '# Datenschutzerklärung',
            '',
            '> **Hinweis:** Diese Erklärung ist eine sorgfältig vorausgefüllte',
            '> Vorlage, ersetzt aber **keine Rechtsberatung**. Bitte ergänzen',
            '> Sie die in eckigen Klammern markierten Angaben des Betreibers.',
            '',
            'Verantwortlich: Beispiel AG, Hafenweg 3, 23552 Lübeck.',
        ]));

        $this->assertSame(['datenschutz'], array_keys((new LegalDocumentStatus)->pending()));
    }

    public function test_the_label_reads_like_a_sentence(): void
    {
        Storage::fake('local');
        $this->fillInAllDocuments();
        Storage::disk('local')->put('legal/impressum.md', '# Impressum von Max Mustermann');

        $this->assertSame('Impressum', (new LegalDocumentStatus)->pendingLabel());

        Storage::disk('local')->put('legal/agb.md', '> **Bitte anpassen.**');
        $this->assertSame('Impressum und AGB', (new LegalDocumentStatus)->pendingLabel());
    }
}

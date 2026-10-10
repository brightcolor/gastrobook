<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\MailFrameFactory;
use App\Mail\TemplatedMail;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const BODY = "Hallo Frau Kessler,\n\nwir freuen uns auf Ihren Besuch.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\n\nReservierung ändern oder stornieren: https://swayy.test/r/abc\n\nBis bald!\nTrattoria Luna";

    /**
     * @param  array<string, mixed>  $location
     * @return array{0: Tenant, 1: Location}
     */
    private function luna(array $location = []): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['name' => 'Luna GmbH', 'imprint_url' => 'https://luna.example/impressum', 'privacy_url' => null, 'brand_logo_path' => null]);
        $setup['location']->update(array_merge(['name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115', 'city' => 'Berlin', 'phone' => '030 1234567', 'email' => 'luna@example.test', 'brand_logo_path' => null], $location));
        $setup['location']->settings()->update(['guest_address' => 'Sie']);
        $this->clearTenantContext();

        return [$setup['tenant']->fresh(), $setup['location']->fresh()];
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function guestMail(string $body = self::BODY, array $location = []): TemplatedMail
    {
        [$tenant, $location] = $this->luna($location);

        return new TemplatedMail('Reservierungsbestätigung', $body, 'Trattoria Luna', null,
            app(MailFrameFactory::class)->forGuest('reservation_confirmed', $tenant, $location));
    }

    public function test_guest_mail_shows_headline_table_button_and_footer(): void
    {
        $html = $this->guestMail()->render();

        $this->assertStringContainsString('Ihre Reservierung ist bestätigt</h1>', $html);
        $this->assertStringContainsString('>Datum</td>', $html);
        $this->assertStringContainsString('>24.11.2026</td>', $html);
        $this->assertStringContainsString('href="https://swayy.test/r/abc"', $html);
        $this->assertStringContainsString('>Reservierung ändern oder stornieren</a>', $html);
        $this->assertStringContainsString('Beispielstraße 12', $html);
        $this->assertStringContainsString('href="tel:0301234567"', $html);
        $this->assertStringContainsString('href="mailto:luna@example.test"', $html);
        $this->assertStringContainsString('href="https://luna.example/impressum"', $html);
        $this->assertStringContainsString('weil Sie bei Trattoria Luna reserviert haben', $html);
        $this->assertStringContainsString('Reserviert mit', $html);
    }

    public function test_html_loads_nothing_from_outside_and_hides_nothing(): void
    {
        $html = $this->guestMail()->render();

        $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('display:none', str_replace(' ', '', $html));
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertLessThan(60 * 1024, strlen($html));
    }

    public function test_text_from_guests_or_tenants_is_escaped_and_only_web_links_are_links(): void
    {
        $html = $this->guestMail("Hallo <b>Gast</b>,\n\nKlick: javascript:alert(1)\n\nName: <img src=x>\nNotiz: \"zitiert\"")->render();

        $this->assertStringContainsString('&lt;b&gt;Gast&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;img src=x&gt;', $html);
    }

    public function test_text_part_carries_the_text_and_the_footer(): void
    {
        $mail = $this->guestMail();

        $mail->assertSeeInText('Reservierung ändern oder stornieren: https://swayy.test/r/abc');
        $mail->assertSeeInText('Beispielstraße 12 · 10115 Berlin');
        $mail->assertSeeInText('Impressum: https://luna.example/impressum');
        $mail->assertSeeInText('weil Sie bei Trattoria Luna reserviert haben');
        $mail->assertDontSeeInText('<td');
    }

    public function test_queued_mail_renders_the_same_after_serialization(): void
    {
        $mail = $this->guestMail();
        $copy = unserialize(serialize($mail));

        $this->assertSame($mail->render(), $copy->render());
    }

    public function test_mail_without_frame_is_a_swayy_notice_with_the_subject_as_headline(): void
    {
        $html = (new TemplatedMail('Lastschrift gekündigt – Luna GmbH', "Hallo,\n\ndas Mandat ist beendet."))->render();

        $this->assertStringContainsString('Lastschrift gekündigt – Luna GmbH</h1>', $html);
        $this->assertStringContainsString('Hilfe &amp; Kontakt', $html);
        $this->assertStringContainsString('alt="Swayy"', $html);
        $this->assertStringNotContainsString('Reserviert mit', $html);
    }

    public function test_logo_is_embedded_and_an_svg_falls_back_to_the_wordmark(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', UploadedFile::fake()->image('logo.png', 200, 100)->getContent());

        $html = $this->guestMail(location: ['brand_logo_path' => 'logos/1/logo.png'])->render();
        $this->assertMatchesRegularExpression('/<img src="(cid:|data:image\/png)[^"]*" alt="Trattoria Luna" width="88" height="44"/', $html);

        Storage::disk('public')->put('logos/1/logo.svg', '<svg/>');
        $html = $this->guestMail(location: ['brand_logo_path' => 'logos/1/logo.svg'])->render();
        $this->assertStringNotContainsString('alt="Trattoria Luna"', $html);
        $this->assertStringContainsString('letter-spacing:-0.015em', $html);
    }

    public function test_dark_mode_and_mobile_rules_are_present(): void
    {
        $html = $this->guestMail()->render();

        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-ogsc]', $html);
        $this->assertStringContainsString('@media (max-width: 520px)', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
    }

    public function test_paragraphs_below_a_divider_are_set_as_fine_print(): void
    {
        $html = $this->guestMail("Bitte bestätigen.\n\n---\n\nDer Link gilt 1 Stunde.")->render();

        $this->assertMatchesRegularExpression('/font-size:13px[^>]*>Der Link gilt 1 Stunde\./', $html);
    }

    public function test_footer_separators_never_glue_words_together(): void
    {
        // SpamAssassin zählt Tags zwischen zwei Wortzeichen als Verschleierung (HTML_OBFUSCATE_*).
        $html = $this->guestMail()->render();

        $this->assertStringContainsString('>·</span>', $html);
        $this->assertDoesNotMatchRegularExpression('/[^\s>]<span class="t-muted"[^>]*>·<\/span>|>·<\/span>[^\s<]/u', $html);
    }

    public function test_light_brand_colors_get_a_readable_button(): void
    {
        [$tenant, $location] = $this->luna();
        $tenant->update(['brand_primary_color' => '#facc15']);
        $mail = new TemplatedMail('Betreff', self::BODY, null, null, app(MailFrameFactory::class)->forGuest('reservation_confirmed', $tenant->fresh(), $location));

        $this->assertStringNotContainsString('background:#facc15', $mail->render());
    }
}

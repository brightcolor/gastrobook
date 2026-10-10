<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BillingRequestConfirmMail;
use App\Mail\BillingRequestOwnerMail;
use App\Mail\ContactRequestMail;
use App\Mail\PasswordResetMail;
use App\Mail\ProvisionedApprovedMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Mail\TrialExpiryWarningMail;
use App\Models\BillingRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SwayyMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function billingRequest(string $notes = ''): BillingRequest
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        return BillingRequest::query()->forceCreate([
            'tenant_id' => $setup['tenant']->id, 'token' => bin2hex(random_bytes(20)), 'plan_key' => 'starter',
            'contact_name' => 'Olivia Owner', 'contact_email' => 'olivia@example.test', 'address_line1' => 'Beispielstraße 12',
            'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE', 'notes' => $notes,
        ]);
    }

    /** @return array<string, array{0: Mailable, 1: string, 2: string, 3: string}> Mail, Überschrift du, Überschrift Sie, Knopftext */
    private function mails(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Trattoria Luna', 'trial_ends_at' => now()->addDays(5)]);
        $request = $this->billingRequest();

        return [
            'password' => [new PasswordResetMail('https://swayy.test/passwort/abc'), 'Neues Passwort festlegen', 'Neues Passwort festlegen', 'Neues Passwort festlegen'],
            'trial' => [new TrialExpiryWarningMail($tenant, 'Olivia'), 'Deine Testphase endet bald', 'Ihre Testphase endet bald', 'Tarif wählen'],
            'billing_confirm' => [new BillingRequestConfirmMail($request), 'Bitte bestätige deine Anfrage', 'Bitte bestätigen Sie Ihre Anfrage', 'Anfrage bestätigen'],
            'welcome' => [new ProvisionedWelcomeMail('Olivia', 'Trattoria Luna', false, 'https://swayy.test/passwort', 'https://swayy.test/login', 'https://panel.test/vertraege'), 'Willkommen bei Swayy', 'Willkommen bei Swayy', 'Zum Kundenbereich'],
            'approved' => [new ProvisionedApprovedMail('Olivia', 'Trattoria Luna', 'https://swayy.test/login'), 'Dein Zugang ist freigeschaltet', 'Ihr Zugang ist freigeschaltet', 'Zur Anmeldung'],
        ];
    }

    public function test_swayy_mails_follow_the_owner_address_and_show_their_button(): void
    {
        config(['app.url' => 'https://swayy.test', 'provisioning.panel_base_url' => 'https://panel.test']);

        foreach (['du' => 1, 'Sie' => 2] as $address => $column) {
            config(['swayy.mail.owner_address' => $address]);
            foreach ($this->mails() as $name => $case) {
                $html = $case[0]->render();
                $this->assertStringContainsString($case[$column].'</h1>', $html, "{$name} ({$address})");
                $this->assertStringContainsString('>'.$case[3].'</a>', $html, "{$name} ({$address}) Knopf");
                $this->assertStringContainsString('alt="Swayy"', $html, $name);
                $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', $html, $name);
            }
        }
    }

    public function test_sie_texts_never_use_du(): void
    {
        config(['swayy.mail.owner_address' => 'Sie', 'app.url' => 'https://swayy.test', 'provisioning.panel_base_url' => 'https://panel.test']);

        foreach ($this->mails() as $name => $case) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dir|dein\w*)\b/i', strip_tags($case[0]->render()), $name);
        }
    }

    public function test_contact_message_is_never_structured(): void
    {
        $html = (new ContactRequestMail('Eve', 'eve@example.test', "Jetzt bezahlen: https://fremde-seite.example/pay\nDatum: heute\nUhrzeit: jetzt"))->render();

        $this->assertStringContainsString('Neue Kontaktanfrage</h1>', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $this->assertStringNotContainsString('class="panel"', $html);
    }

    public function test_customer_note_in_billing_owner_mail_never_becomes_a_button(): void
    {
        config(['app.url' => 'https://swayy.test']);
        $html = (new BillingRequestOwnerMail($this->billingRequest('Mehr Infos: https://fremde-seite.example/x')))->render();

        $this->assertStringContainsString('Neue Tarifanfrage von', $html);
        $this->assertDoesNotMatchRegularExpression('/<a href="https:\/\/fremde-seite\.example\/x" style="display:inline-block/', $html);
        $this->assertStringContainsString('>Konto freischalten</a>', $html);
    }

    public function test_billing_link_validity_comes_from_the_settings(): void
    {
        config(['swayy.billing_request.confirm_hours' => 24, 'swayy.mail.owner_address' => 'du']);

        $this->assertStringContainsString('Der Link gilt 24 Stunden.', (new BillingRequestConfirmMail($this->billingRequest()))->render());
    }
}

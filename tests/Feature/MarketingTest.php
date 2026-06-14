<?php

namespace Tests\Feature;

use App\Mail\ContactRequestMail;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_shows_features_and_pricing(): void
    {
        $this->seed(PlanSeeder::class);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Swayy')
            ->assertSee('Professional')
            ->assertSee('Kostenlos testen');
    }

    public function test_landing_page_works_without_seeded_plans(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_legal_pages_render(): void
    {
        $this->get('/impressum')->assertOk()->assertSee('Impressum');
        $this->get('/datenschutz')->assertOk()->assertSee('Datenschutz');
        $this->get('/agb')->assertOk()->assertSee('Gesch');
    }

    public function test_contact_form_sends_mail_to_support_address(): void
    {
        Mail::fake();
        config(['services.support_email' => 'support@swayy.test']);

        $response = $this->post('/kontakt', [
            'name' => 'Max Muster',
            'email' => 'max@example.com',
            'message' => 'Ich interessiere mich für den Enterprise-Tarif.',
        ]);

        $response->assertRedirect();
        Mail::assertSent(ContactRequestMail::class, function (ContactRequestMail $mail) {
            return $mail->hasTo('support@swayy.test')
                && $mail->senderEmail === 'max@example.com';
        });
    }

    public function test_contact_form_honeypot_blocks_bots(): void
    {
        Mail::fake();

        $this->post('/kontakt', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'spam',
            'website' => 'http://spam.example',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }
}

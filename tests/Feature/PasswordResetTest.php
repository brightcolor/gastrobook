<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_receives_reset_mail_sent_not_queued(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'boss@swayy.test', 'saas_role' => 'super_admin']);

        $this->post('/passwort-vergessen', ['email' => 'boss@swayy.test'])
            ->assertSessionHas('status');

        // Sent synchronously (not queued) → independent of a running queue worker.
        Mail::assertSent(PasswordResetMail::class);
        Mail::assertNotQueued(PasswordResetMail::class);
    }

    public function test_normal_user_receives_reset_mail(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'normal@swayy.test', 'saas_role' => null]);

        $this->post('/passwort-vergessen', ['email' => 'normal@swayy.test']);

        Mail::assertSent(PasswordResetMail::class);
    }

    public function test_unknown_email_sends_nothing_but_shows_generic_status(): void
    {
        Mail::fake();

        $this->post('/passwort-vergessen', ['email' => 'doesnotexist@swayy.test'])
            ->assertSessionHas('status'); // enumeration-safe generic message

        Mail::assertNothingSent();
    }
}

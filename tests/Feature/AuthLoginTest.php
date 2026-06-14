<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_shows_translated_message_not_raw_key(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'is_active' => true,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        // The key must resolve to a real sentence (lang file present), and the
        // controller must surface that resolved message – never the raw key.
        $this->assertNotSame('auth.failed', __('auth.failed'));
        $response->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'gesperrt@example.com',
            'password' => 'correct-horse-battery',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => 'gesperrt@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $this->assertGuest();
    }

    public function test_active_account_logs_in(): void
    {
        $user = User::factory()->create([
            'email' => 'ok@example.com',
            'password' => 'correct-horse-battery',
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => 'ok@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->assertAuthenticatedAs($user);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_rejects_password_without_numbers(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'noletter',
            'password_confirmation' => 'noletter',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_accepts_valid_password(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'neues123passwort',
            'password_confirmation' => 'neues123passwort',
        ])->assertRedirect(route('login'));
    }

    public function test_reset_accepts_password_without_mixed_case(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'kleinbuchstaben99',
            'password_confirmation' => 'kleinbuchstaben99',
        ])->assertRedirect(route('login'));
    }
}

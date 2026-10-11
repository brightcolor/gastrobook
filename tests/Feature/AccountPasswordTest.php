<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    private function ownerWithPassword(string $password = 'altes-Passwort-1'): User
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make($password)]);
        $this->clearTenantContext();

        return $owner;
    }

    public function test_the_account_page_offers_the_password_card(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/account')
            ->assertOk()
            ->assertSee('id="passwort"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('autocomplete="new-password"', false);

        $html = (string) $response->getContent();
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
        $this->assertStringContainsString('data-ajax data-reset-after-save', $html);
        $this->assertStringContainsString('action="'.route('admin.account.password').'"', $html);
        $this->assertStringContainsString('Mindestens 8 Zeichen, mit Buchstaben und Ziffern.', $html);
        $this->assertStringNotContainsString('data-savebar', $html);
        $this->assertStringContainsString('Passwort ändern', $html);
        $this->assertStringContainsString('Auf allen anderen Geräten meldest du dich mit dem neuen Passwort neu an.', $html);
        // Reihenfolge der Karten: Profil, Passwort, Konto löschen.
        $this->assertTrue(strpos($html, 'id="profil"') < strpos($html, 'id="passwort"'));
        $this->assertTrue(strpos($html, 'id="passwort"') < strpos($html, 'id="konto-loeschen"'));
    }

    public function test_the_help_text_names_the_configured_minimum_length(): void
    {
        config(['swayy.auth.password_min_length' => 14]);
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->get('/admin/account')
            ->assertOk()
            ->assertSee('Mindestens 14 Zeichen, mit Buchstaben und Ziffern.');
    }

    public function test_the_page_never_prints_a_password_back(): void
    {
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->from('/admin/account')->put('/admin/account/password', [
            'current_password' => 'falsch-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertRedirect('/admin/account')->assertSessionHasErrors('current_password');

        $this->actingAs($owner)->get('/admin/account')
            ->assertOk()
            ->assertDontSee('neues-Passwort-2')
            ->assertDontSee('falsch-1');
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $owner = $this->ownerWithPassword();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'falsch-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertStatus(422);

        $this->assertSame('Das aktuelle Passwort stimmt nicht. Bitte erneut eingeben oder „Passwort vergessen“ nutzen.', $response->json('errors.current_password.0'));
        $this->assertTrue(Hash::check('altes-Passwort-1', $owner->fresh()->password));
        $this->assertSame(0, AuditLog::where('action', 'account.password_changed')->count());
    }

    public function test_a_missing_current_password_is_named(): void
    {
        $owner = $this->ownerWithPassword();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertStatus(422);

        $this->assertSame('Bitte dein aktuelles Passwort eingeben.', $response->json('errors.current_password.0'));
    }

    public function test_the_repetition_must_match(): void
    {
        $owner = $this->ownerWithPassword();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'anderes-Passwort-3',
        ])->assertStatus(422);

        $this->assertSame('Die Wiederholung passt nicht zum neuen Passwort. Bitte beide Felder gleich ausfüllen.', $response->json('errors.password.0'));
        $this->assertTrue(Hash::check('altes-Passwort-1', $owner->fresh()->password));
    }

    public function test_an_empty_new_password_is_named(): void
    {
        $owner = $this->ownerWithPassword();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1',
        ])->assertStatus(422);

        $this->assertSame('Bitte ein neues Passwort eingeben.', $response->json('errors.password.0'));
    }

    public function test_the_new_password_needs_a_letter_and_a_digit(): void
    {
        $owner = $this->ownerWithPassword();

        $noDigit = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'nur-buchstaben', 'password_confirmation' => 'nur-buchstaben',
        ])->assertStatus(422);
        $this->assertSame('Das neue Passwort braucht mindestens eine Ziffer.', $noDigit->json('errors.password.0'));

        $noLetter = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => '1234567890', 'password_confirmation' => '1234567890',
        ])->assertStatus(422);
        $this->assertSame('Das neue Passwort braucht mindestens einen Buchstaben.', $noLetter->json('errors.password.0'));
    }

    public function test_the_minimum_length_comes_from_the_settings(): void
    {
        config(['swayy.auth.password_min_length' => 12]);
        $owner = $this->ownerWithPassword();

        $response = $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'kurz-Pass-1', 'password_confirmation' => 'kurz-Pass-1',
        ])->assertStatus(422);

        $this->assertStringContainsString('12', $response->json('errors.password.0'));
        $this->assertSame('Das neue Passwort braucht mindestens 12 Zeichen. Bitte ein längeres wählen.', $response->json('errors.password.0'));
        $this->assertTrue(Hash::check('altes-Passwort-1', $owner->fresh()->password));
    }

    public function test_eleven_characters_pass_with_the_default_minimum_length(): void
    {
        // 11 Zeichen: unter 12, über der Vorgabe 8.
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'kurz-Pass-1', 'password_confirmation' => 'kurz-Pass-1',
        ])->assertOk();
    }

    public function test_a_new_password_works_and_is_logged_without_the_password(): void
    {
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertOk()->assertJsonPath('message', 'Passwort geändert.');

        $this->assertTrue(Hash::check('neues-Passwort-2', $owner->fresh()->password));
        $this->assertFalse(Hash::check('altes-Passwort-1', $owner->fresh()->password));
        $log = AuditLog::where('action', 'account.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('neues-Passwort-2', json_encode([$log->old_values, $log->new_values, $log->metadata]));
        $this->assertStringNotContainsString('altes-Passwort-1', json_encode([$log->old_values, $log->new_values, $log->metadata]));
        $this->assertStringNotContainsString($owner->fresh()->password, (string) json_encode($log->getAttributes()));
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame($owner->current_tenant_id, $log->tenant_id);
        $this->assertSame(User::class, $log->entity_type);
        $this->assertSame($owner->id, $log->entity_id);
    }

    public function test_the_new_password_signs_in_and_the_old_one_does_not(): void
    {
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertOk();

        Auth::guard('web')->logout();

        $this->assertFalse(Auth::attempt(['email' => $owner->email, 'password' => 'altes-Passwort-1']));
        $this->assertTrue(Auth::attempt(['email' => $owner->email, 'password' => 'neues-Passwort-2']));
    }

    public function test_a_classic_save_returns_to_the_account_page_with_the_message(): void
    {
        $owner = $this->ownerWithPassword();

        $this->actingAs($owner)->from('/admin/account')->put('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertRedirect('/admin/account')->assertSessionHas('success', 'Passwort geändert.');
    }

    public function test_the_session_gets_a_new_id(): void
    {
        $owner = $this->ownerWithPassword();
        $before = Str::random(40);

        $this->withCookie((string) config('session.cookie'), $before)->actingAs($owner)->put('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_page_is_for_signed_in_users_only(): void
    {
        $this->putJson('/admin/account/password', [
            'current_password' => 'a', 'password' => 'b', 'password_confirmation' => 'b',
        ])->assertUnauthorized();
    }

    public function test_any_role_may_change_the_own_password(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $host->update(['password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        $this->actingAs($host)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertOk();
    }

    public function test_a_platform_admin_without_a_business_changes_the_password_and_the_entry_has_no_business(): void
    {
        $admin = User::factory()->create(['saas_role' => 'super_admin', 'current_tenant_id' => null, 'password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/account')->assertOk()->assertSee('id="passwort"', false);
        $this->actingAs($admin)->putJson('/admin/account/password', [
            'current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2',
        ])->assertOk()->assertJsonPath('message', 'Passwort geändert.');

        $this->assertTrue(Hash::check('neues-Passwort-2', $admin->fresh()->password));
        $log = AuditLog::where('action', 'account.password_changed')->firstOrFail();
        $this->assertNull($log->tenant_id);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_the_attempts_per_minute_come_from_the_settings(): void
    {
        config(['swayy.auth.password_change_per_minute' => 3]);
        $owner = $this->ownerWithPassword();
        $payload = ['current_password' => 'falsch-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2'];

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner)->putJson('/admin/account/password', $payload)->assertStatus(422);
        }

        $response = $this->actingAs($owner)->putJson('/admin/account/password', $payload)->assertStatus(429);
        $this->assertStringContainsString('Zu viele Anfragen', (string) $response->json('message'));
    }

    public function test_a_higher_setting_allows_more_attempts_and_users_do_not_share_the_limit(): void
    {
        config(['swayy.auth.password_change_per_minute' => 5]);
        $owner = $this->ownerWithPassword();
        $other = $this->ownerWithPassword();
        $payload = ['current_password' => 'falsch-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2'];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->putJson('/admin/account/password', $payload)->assertStatus(422);
        }
        $this->actingAs($owner)->putJson('/admin/account/password', $payload)->assertStatus(429);

        // Ein anderes Konto meldet sich auf einem anderen Gerät an: eigene Sitzung.
        session()->flush();
        $this->actingAs($other)->putJson('/admin/account/password', $payload)->assertStatus(422);
    }

    public function test_the_reset_form_follows_the_configured_minimum_length(): void
    {
        config(['swayy.auth.password_min_length' => 12]);
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'kurz-Pass-1', 'password_confirmation' => 'kurz-Pass-1',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'lang-genug-Pass-1', 'password_confirmation' => 'lang-genug-Pass-1',
        ])->assertRedirect(route('login'));
    }

    public function test_the_reset_page_names_the_configured_minimum_length(): void
    {
        config(['swayy.auth.password_min_length' => 12]);

        $this->get(route('password.reset', ['token' => 'x', 'email' => 'person@example.test']))
            ->assertOk()
            ->assertSee('Mindestens 12 Zeichen, mit Buchstaben und Ziffern.');
    }

    public function test_the_password_settings_have_bounds_defaults_and_documentation(): void
    {
        [$config, $warnings] = $this->swayyConfig([]);
        $this->assertSame(8, $config['auth']['password_min_length']);
        $this->assertSame(6, $config['auth']['password_change_per_minute']);
        $this->assertSame('', $warnings);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_PASSWORD_MIN_LENGTH' => '1', 'SWAYY_PASSWORD_CHANGE_PER_MINUTE' => '1']);
        $this->assertSame(8, $config['auth']['password_min_length']);
        $this->assertSame(3, $config['auth']['password_change_per_minute']);
        $this->assertStringContainsString('SWAYY_PASSWORD_MIN_LENGTH', $warnings);
        $this->assertStringContainsString('SWAYY_PASSWORD_CHANGE_PER_MINUTE', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_PASSWORD_MIN_LENGTH' => '500', 'SWAYY_PASSWORD_CHANGE_PER_MINUTE' => '500']);
        $this->assertSame(64, $config['auth']['password_min_length']);
        $this->assertSame(30, $config['auth']['password_change_per_minute']);

        [$config] = $this->swayyConfig(['SWAYY_PASSWORD_MIN_LENGTH' => '16', 'SWAYY_PASSWORD_CHANGE_PER_MINUTE' => '10']);
        $this->assertSame(16, $config['auth']['password_min_length']);
        $this->assertSame(10, $config['auth']['password_change_per_minute']);

        $this->assertDocumentedInEnvExample(['SWAYY_PASSWORD_MIN_LENGTH', 'SWAYY_PASSWORD_CHANGE_PER_MINUTE']);
    }

    public function test_the_audit_label_is_german(): void
    {
        $this->assertSame('Passwort geändert', AuditLog::ACTION_LABELS['account.password_changed']);
    }
}

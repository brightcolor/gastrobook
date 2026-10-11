<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Support\PasswordPolicy;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

/**
 * Eine Regel für jedes neue Passwort: Mindestlänge aus swayy.auth.password_min_length, dazu Buchstaben und Ziffern.
 * Anmeldung per Registrierung, Einladung, Zurücksetzen, „Mein Konto“, Plattform-Benutzer und Befehl swayy:create-admin
 * lesen dieselbe Einstellung.
 */
class PasswordPolicyTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    private const MIN = 14;

    /** Ein Zeichen zu kurz. */
    private const TOO_SHORT = 'kurzes-Pass-1';

    /** Lang genug, ohne Ziffer. */
    private const NO_DIGIT = 'nur-buchstaben-lang';

    /** Lang genug, ohne Buchstaben. */
    private const NO_LETTER = '12345678901234567890';

    /** Genau 14 Zeichen mit Buchstaben und Ziffer. */
    private const GOOD = 'laenger-Pass-1';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.auth.password_min_length' => self::MIN]);
    }

    /** @return list<string> */
    private function rejected(): array
    {
        return [self::TOO_SHORT, self::NO_DIGIT, self::NO_LETTER];
    }

    private function email(): string
    {
        return 'person'.(++$this->counter).'@example.test';
    }

    private function register(string $password): TestResponse
    {
        return $this->from('/register')->post('/register', [
            'restaurant_name' => 'Trattoria Test '.$this->counter, 'name' => 'Test Person', 'email' => $this->email(),
            'password' => $password, 'password_confirmation' => $password, 'privacy_accepted' => '1',
        ]);
    }

    public function test_the_registration_applies_the_rule(): void
    {
        $this->seed(PlanSeeder::class);

        foreach ($this->rejected() as $password) {
            $this->register($password)->assertSessionHasErrors('password');
        }
        $this->assertSame(0, User::count());

        $this->register(self::GOOD)->assertSessionHasNoErrors()->assertRedirect(route('admin.onboarding.show'));
    }

    public function test_the_invitation_applies_the_rule(): void
    {
        $setup = $this->createTenantSetup();
        $invitation = Invitation::create(['tenant_id' => $setup['tenant']->id, 'email' => 'neu@example.test', 'role' => 'host', 'all_locations' => true]);
        $this->clearTenantContext();

        foreach ($this->rejected() as $password) {
            $this->from('/invitation/'.$invitation->token)->post('/invitation/'.$invitation->token, [
                'name' => 'Neue Kollegin', 'password' => $password, 'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');
        }
        $this->assertDatabaseMissing('users', ['email' => 'neu@example.test']);

        $this->post('/invitation/'.$invitation->token, ['name' => 'Neue Kollegin', 'password' => self::GOOD, 'password_confirmation' => self::GOOD])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_reset_applies_the_rule(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        foreach ($this->rejected() as $password) {
            $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password])
                ->assertSessionHasErrors('password');
        }

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => self::GOOD, 'password_confirmation' => self::GOOD])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
    }

    public function test_the_change_under_my_account_applies_the_rule(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make('altes-Passwort-1')]);
        $this->clearTenantContext();

        foreach ($this->rejected() as $password) {
            $this->actingAs($owner)->putJson('/admin/account/password', ['current_password' => 'altes-Passwort-1', 'password' => $password, 'password_confirmation' => $password])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->actingAs($owner)->putJson('/admin/account/password', ['current_password' => 'altes-Passwort-1', 'password' => self::GOOD, 'password_confirmation' => self::GOOD])->assertOk();
    }

    public function test_a_new_platform_user_applies_the_rule(): void
    {
        $admin = User::factory()->create(['saas_role' => 'super_admin']);
        $this->clearTenantContext();

        foreach ($this->rejected() as $password) {
            $this->actingAs($admin)->from('/saas/users')->post('/saas/users', ['name' => 'Neue Kraft', 'email' => $this->email(), 'password' => $password])
                ->assertSessionHasErrors('password');
        }

        // Ohne Feld saas_role (Aufrufer ohne Formular) legt der Controller einen Benutzer ohne Plattform-Rolle an.
        $this->actingAs($admin)->post('/saas/users', ['name' => 'Neue Kraft', 'email' => 'kraft@example.test', 'password' => self::GOOD])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'kraft@example.test']);
    }

    public function test_the_command_for_the_first_admin_applies_the_rule(): void
    {
        foreach ($this->rejected() as $password) {
            $this->artisan('swayy:create-admin', ['--email' => 'chef@example.test', '--password' => $password, '--name' => 'Chef'])->assertFailed();
        }
        $this->assertDatabaseMissing('users', ['email' => 'chef@example.test']);

        $this->artisan('swayy:create-admin', ['--email' => 'chef@example.test', '--password' => self::GOOD, '--name' => 'Chef'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'chef@example.test', 'saas_role' => 'super_admin']);
    }

    public function test_the_command_names_the_configured_length(): void
    {
        $this->artisan('swayy:create-admin', ['--email' => 'chef@example.test', '--password' => self::TOO_SHORT, '--name' => 'Chef'])
            ->expectsOutputToContain('14')
            ->assertFailed();
    }

    public function test_every_form_names_the_configured_length(): void
    {
        $this->seed(PlanSeeder::class);
        $hint = 'Mindestens '.self::MIN.' Zeichen, mit Buchstaben und Ziffern.';

        $this->get('/register')->assertOk()->assertSee($hint)->assertSee('minlength="'.self::MIN.'"', false)->assertDontSee('mind. 10');

        $setup = $this->createTenantSetup();
        $invitation = Invitation::create(['tenant_id' => $setup['tenant']->id, 'email' => 'neu@example.test', 'role' => 'host', 'all_locations' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->get('/invitation/'.$invitation->token)->assertOk()->assertSee($hint)->assertSee('minlength="'.self::MIN.'"', false)->assertDontSee('min. 10');

        $this->get(route('password.reset', ['token' => 'x', 'email' => 'person@example.test']))->assertOk()->assertSee($hint);

        $this->actingAs($owner)->get('/admin/account')->assertOk()->assertSee($hint);

        $admin = User::factory()->create(['saas_role' => 'super_admin']);
        $this->actingAs($admin)->get('/saas/users')->assertOk()->assertSee($hint)->assertSee('minlength="'.self::MIN.'"', false)->assertDontSee('min. 10');
    }

    public function test_the_default_is_ten_so_no_entry_point_gets_weaker(): void
    {
        [$config, $warnings] = $this->swayyConfig([]);
        $this->assertSame(10, $config['auth']['password_min_length']);
        $this->assertSame('', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_PASSWORD_MIN_LENGTH' => '1']);
        $this->assertSame(8, $config['auth']['password_min_length']);
        [$config] = $this->swayyConfig(['SWAYY_PASSWORD_MIN_LENGTH' => '500']);
        $this->assertSame(64, $config['auth']['password_min_length']);

        $this->assertStringContainsString('Vorgabe 10', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_the_policy_reads_the_setting_on_every_call(): void
    {
        $this->assertSame(self::MIN, PasswordPolicy::minLength());
        config(['swayy.auth.password_min_length' => 20]);
        $this->assertSame(20, PasswordPolicy::minLength());
        $this->assertSame('Mindestens 20 Zeichen, mit Buchstaben und Ziffern.', PasswordPolicy::hint());
    }
}

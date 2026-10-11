<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Invitation;
use App\Models\User;
use App\Provisioning\LoginLinks;
use App\Services\GuestAuthService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein Passwortwechsel beendet die offenen Sitzungen aller anderen Geräte
 * (Middleware AuthenticateSession in der Web-Gruppe, bootstrap/app.php).
 *
 * Ein Test sieht normalerweise nur eine Sitzung: Der Speicher bleibt zwischen
 * den Anfragen im Arbeitsspeicher stehen. Die Hilfen legen darum jedes Gerät
 * als eigene Sitzung im Speicher an und schalten vor jeder Anfrage auf dieses
 * Gerät um.
 */
class AuthenticatedSessionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function owner(string $password = 'altes-Passwort-1'): User
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make($password)]);
        $this->clearTenantContext();

        return $owner;
    }

    /**
     * Eine Sitzung, wie sie ein Gerät nach der Anmeldung hätte. Ohne
     * $passwordHash fehlt der gemerkte Hash, wie in Sitzungen aus der Zeit vor
     * der Middleware.
     */
    private function seedSession(User $user, ?string $passwordHash): string
    {
        $guard = $this->app['auth']->guard('web');
        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($id = Str::random(40));
        $store->put($guard->getName(), $user->id);
        if ($passwordHash !== null) {
            $store->put('password_hash_web', $guard->hashPasswordForCookie($passwordHash));
        }
        $store->save();
        $store->flush();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    /** Die nächste Anfrage kommt von dem Gerät mit dieser Sitzung. */
    private function onDevice(string $sessionId): static
    {
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();

        // JSON-Anfragen senden Cookies nur mit withCredentials().
        return $this->withCredentials()->withCookie((string) config('session.cookie'), $sessionId);
    }

    /** Die Sitzung der letzten Anmeldung trägt den Hash und besteht die nächste Anfrage. */
    private function assertSessionStaysSignedIn(): void
    {
        $this->assertNotNull(session()->get('password_hash_web'));

        $this->onDevice(session()->getId())->get('/admin/account')->assertOk();
        $this->assertAuthenticated();
    }

    /**
     * @return array<string, string>
     */
    private function newPassword(): array
    {
        return ['current_password' => 'altes-Passwort-1', 'password' => 'neues-Passwort-2', 'password_confirmation' => 'neues-Passwort-2'];
    }

    public function test_a_password_change_ends_the_other_sessions_and_keeps_the_current_one(): void
    {
        $owner = $this->owner();
        $current = $this->seedSession($owner, $owner->password);
        $phone = $this->seedSession($owner, $owner->password);
        $laptop = $this->seedSession($owner, $owner->password);

        $this->onDevice($current)->get('/admin/account')->assertOk();
        $this->onDevice($phone)->get('/admin/account')->assertOk();

        $this->onDevice($current)->putJson('/admin/account/password', $this->newPassword())->assertOk();
        $renewed = session()->getId();
        $this->assertNotSame($current, $renewed);

        // Das Gerät, das das Passwort geändert hat, bleibt angemeldet.
        $this->onDevice($renewed)->get('/admin/account')->assertOk();
        $this->assertAuthenticatedAs($owner);

        // Die anderen Geräte landen auf der Anmeldung.
        $this->onDevice($phone)->get('/admin/account')->assertRedirect(route('login'));
        $this->assertGuest();

        // Fetch-Anfragen der Verwaltung bekommen JSON mit Grund und nächstem Schritt.
        $this->onDevice($laptop)->getJson('/admin/account')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Die Anmeldung ist abgelaufen. Bitte neu anmelden und es dann noch einmal versuchen.');
    }

    public function test_the_session_id_from_before_the_change_is_ended_too(): void
    {
        $owner = $this->owner();
        $current = $this->seedSession($owner, $owner->password);

        $this->onDevice($current)->putJson('/admin/account/password', $this->newPassword())->assertOk();

        // Wer die alte Kennung mitgeschnitten hat, kommt damit nicht mehr hinein.
        $this->onDevice($current)->get('/admin/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_rejected_change_leaves_every_session_signed_in(): void
    {
        $owner = $this->owner();
        $current = $this->seedSession($owner, $owner->password);
        $phone = $this->seedSession($owner, $owner->password);

        $this->onDevice($current)->putJson('/admin/account/password', ['current_password' => 'falsch-1'] + $this->newPassword())
            ->assertStatus(422);

        $this->onDevice($phone)->get('/admin/account')->assertOk();
        $this->onDevice($current)->get('/admin/account')->assertOk();
    }

    public function test_a_session_without_a_stored_hash_is_not_ended_and_learns_it(): void
    {
        $owner = $this->owner();
        $legacy = $this->seedSession($owner, null);

        $this->onDevice($legacy)->get('/admin/account')->assertOk();
        $this->assertNotNull(session()->get('password_hash_web'));

        // Danach schützt der gemerkte Hash die Sitzung wie jede andere.
        $owner->forceFill(['password' => Hash::make('anderes-Passwort-3')])->save();
        $this->onDevice($legacy)->get('/admin/account')->assertRedirect(route('login'));
    }

    public function test_resetting_a_forgotten_password_ends_the_open_sessions(): void
    {
        $owner = $this->owner();
        $device = $this->seedSession($owner, $owner->password);
        $this->onDevice($device)->get('/admin/account')->assertOk();

        // Das Zurücksetzen kommt von einem anderen Gerät, ohne Anmeldung.
        $token = Password::broker()->createToken($owner);
        $this->onDevice(Str::random(40))->post(route('password.update'), [
            'token' => $token, 'email' => $owner->email,
            'password' => 'zurueckgesetzt-Passwort-3', 'password_confirmation' => 'zurueckgesetzt-Passwort-3',
        ])->assertRedirect(route('login'));

        $this->onDevice($device)->get('/admin/account')->assertRedirect(route('login'));
    }

    public function test_the_login_form_signs_in_and_the_session_survives(): void
    {
        $owner = $this->owner('login-Passwort-1');

        $this->post('/login', ['email' => $owner->email, 'password' => 'login-Passwort-1'])->assertRedirect();

        $this->assertSessionStaysSignedIn();
    }

    public function test_a_login_with_remember_me_survives(): void
    {
        $owner = $this->owner('login-Passwort-1');

        $response = $this->post('/login', ['email' => $owner->email, 'password' => 'login-Passwort-1', 'remember' => '1'])->assertRedirect();

        $this->assertNotEmpty(collect($response->headers->getCookies())->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')));
        $this->assertSessionStaysSignedIn();
    }

    public function test_the_registration_signs_in_and_the_session_survives(): void
    {
        $this->seed(PlanSeeder::class);

        $this->post('/register', [
            'restaurant_name' => 'Trattoria Bella',
            'name' => 'Maria Rossi',
            'email' => 'maria@bella.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
            'privacy_accepted' => '1',
        ])->assertRedirect(route('admin.onboarding.show'));

        $this->assertSessionStaysSignedIn();
    }

    public function test_the_invitation_signs_in_and_the_session_survives(): void
    {
        $setup = $this->createTenantSetup();
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu@example.test',
            'role' => 'host',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->post('/invitation/'.$invitation->token, [
            'name' => 'Neue Kollegin',
            'password' => 'ein-langes-passwort-1',
            'password_confirmation' => 'ein-langes-passwort-1',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertSessionStaysSignedIn();
    }

    public function test_the_login_link_from_the_customer_panel_signs_in_and_the_session_survives(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $url = app(LoginLinks::class)->issue($owner, $setup['tenant'])['url'];
        $this->clearTenantContext();

        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('admin.dashboard'));

        $this->assertSessionStaysSignedIn();
    }

    public function test_the_login_link_from_the_customer_panel_ends_with_a_password_change_on_another_device(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->update(['password' => Hash::make('altes-Passwort-1')]);
        $url = app(LoginLinks::class)->issue($owner, $setup['tenant'])['url'];
        $this->clearTenantContext();

        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('admin.dashboard'));
        $panelSession = session()->getId();

        $other = $this->seedSession($owner, $owner->fresh()->password);
        $this->onDevice($other)->putJson('/admin/account/password', $this->newPassword())->assertOk();

        $this->onDevice($panelSession)->get('/admin/account')->assertRedirect(route('login'));
    }

    public function test_the_guest_portal_keeps_its_own_session(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::create([
            'tenant_id' => $setup['tenant']->id, 'first_name' => 'Stamm', 'last_name' => 'Gast',
            'email' => 'stamm@example.test', 'source' => 'online',
        ]);
        $token = app(GuestAuthService::class)->issue($guest, 'login', null, 60);
        $this->clearTenantContext();

        $slug = $setup['tenant']->slug;
        $this->get('/konto/'.$slug.'/login/'.$token)->assertRedirect(route('guest.portal.dashboard', $slug));
        $portalSession = session()->getId();

        $this->onDevice($portalSession)->get(route('guest.portal.dashboard', $slug))->assertOk()->assertSee('Stamm');
        $this->assertGuest();
    }

    public function test_the_support_access_keeps_the_support_session(): void
    {
        $setup = $this->createTenantSetup();
        $support = User::factory()->create(['saas_role' => 'support_admin']);
        $this->clearTenantContext();
        $session = $this->seedSession($support, $support->password);

        $this->onDevice($session)->post('/saas/tenants/'.$setup['tenant']->id.'/impersonate')->assertRedirect();
        $this->assertSame($setup['tenant']->id, $support->fresh()->current_tenant_id);

        $this->onDevice($session)->get('/admin/account')->assertOk();
        $this->assertAuthenticatedAs($support);
    }
}

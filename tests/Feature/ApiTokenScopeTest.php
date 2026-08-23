<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein API-Token folgt der heutigen Rolle, nicht der von damals.
 *
 * Vorher prüfte die Schnittstelle nur die Umfänge des Tokens. Wer vom Inhaber
 * auf „nur lesen" herabgestuft wurde, konnte damit weiter Reservierungen
 * anlegen und Webhook-Ziele setzen — in der Oberfläche wirkte die Herabstufung
 * sofort, auf dem Schnittstellenweg gar nicht.
 */
class ApiTokenScopeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<int, string>  $scopes
     */
    private function tokenFor(array $setup, User $user, array $scopes): string
    {
        return $user->createToken('test', array_merge(
            ['tenant:'.$setup['tenant']->id],
            $scopes,
        ))->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array<string, mixed>
     */
    private function buchung(array $setup): array
    {
        return [
            'location_id' => $setup['location']->id,
            'date' => now()->addDays(2)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'guest_name' => 'Frau Kessler',
            'guest_email' => 'kessler@example.test',
        ];
    }

    public function test_an_owner_writes_through_the_api(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $this->tokenFor($setup, $user, ['reservations:write']);
        $this->clearTenantContext();

        $this->withToken($token)->postJson('/api/v1/reservations', $this->buchung($setup))
            ->assertStatus(201);
    }

    /**
     * Derselbe Token, dieselben Umfaenge - nur die Rolle ist eine andere. Die
     * Herabstufung wirkte vorher nur in der Oberflaeche.
     */
    public function test_a_demoted_member_can_no_longer_write_through_the_api(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $this->tokenFor($setup, $user, ['reservations:write']);

        TenantUser::where('tenant_id', $setup['tenant']->id)
            ->where('user_id', $user->id)
            ->update(['role' => 'readonly']);
        $this->clearTenantContext();

        $this->withToken($token)->postJson('/api/v1/reservations', $this->buchung($setup))
            ->assertForbidden();

        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
    }

    public function test_reading_still_works_after_the_demotion(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $this->tokenFor($setup, $user, ['reservations:read']);
        TenantUser::where('tenant_id', $setup['tenant']->id)
            ->where('user_id', $user->id)
            ->update(['role' => 'readonly']);
        $this->clearTenantContext();

        // 'readonly' darf .view - das Lesen bleibt.
        $this->withToken($token)->getJson('/api/v1/reservations')->assertOk();
    }

    /**
     * Der eingeengte Umfang darf nicht in der Datenbank landen — sonst waere
     * der Token nach einem einzigen Aufruf dauerhaft beschnitten, auch wenn die
     * Rolle spaeter zurueckkommt.
     */
    public function test_the_narrowing_does_not_touch_the_stored_token(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $this->tokenFor($setup, $user, ['reservations:write']);
        TenantUser::where('tenant_id', $setup['tenant']->id)
            ->where('user_id', $user->id)
            ->update(['role' => 'readonly']);
        $this->clearTenantContext();

        $this->withToken($token)->getJson('/api/v1/reservations');

        $gespeichert = $user->tokens()->first();
        $this->assertContains('reservations:write', $gespeichert->abilities);
    }

    /**
     * Wer api_tokens.manage hat, muss den Bestand des Betriebs sehen und
     * widerrufen koennen - vorher sah jeder nur seine eigenen.
     */
    public function test_an_owner_sees_and_revokes_a_colleagues_token(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $kollege = $this->createMember($setup['tenant'], 'host');
        $fremder = $this->tokenFor($setup, $kollege, ['reservations:read']);
        $this->clearTenantContext();

        $tokenId = $kollege->tokens()->first()->id;

        $this->actingAs($owner)->get('/admin/api-tokens')
            ->assertOk()
            ->assertSee($kollege->name);

        $this->actingAs($owner)->delete('/admin/api-tokens/'.$tokenId)
            ->assertRedirect();

        $this->assertSame(0, $kollege->tokens()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ProvisionedApprovedMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Models\Location;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Provisioning\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Bereitstellungs-Schnittstelle, Version 1 (Skill kundenpanel-saas).
 */
final class ProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'testschluessel-0123456789abcdef0123456789abcdef';

    private const SECOND_KEY = 'zweitschluessel-0123456789abcdef0123456789abcd';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'provisioning.keys' => [self::KEY],
            'provisioning.service_code' => 'swayy',
            'provisioning.allowed_networks' => [],
            'provisioning.max_failures' => 10,
            'provisioning.lock_seconds' => 900,
            'provisioning.max_skew_seconds' => 300,
            'provisioning.login_link_seconds' => 120,
            'provisioning.panel_base_url' => 'https://panel.example.test',
            'provisioning.panel_order_url' => '',
        ]);
        RateLimiter::clear('provisioning:127.0.0.1');
        Plan::factory()->create(['key' => 'trial', 'name' => 'Trial', 'trial_days' => 30, 'limits' => ['max_locations' => 1, 'max_tables' => 15]]);
        Plan::factory()->create(['key' => 'starter', 'name' => 'Starter', 'limits' => ['max_locations' => 1, 'max_tables' => 15, 'max_users' => 20]]);
        Mail::fake();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $headers
     */
    private function api(string $method, string $path, ?array $payload = null, ?string $key = null, array $headers = [], ?int $time = null, ?string $rawBody = null): TestResponse
    {
        $body = $rawBody ?? ($payload === null ? '' : ($payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $target = '/provisioning/v1'.$path;
        $stamp = (string) ($time ?? time());
        $server = [
            'HTTP_X_PROVISIONING_TIMESTAMP' => $stamp,
            'HTTP_X_PROVISIONING_SIGNATURE' => 'v1='.Signature::request($key ?? self::KEY, $stamp, $method, $target, $body),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $server['HTTP_IDEMPOTENCY_KEY'] = bin2hex(random_bytes(16));
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $target, [], [], [], $server, $body);
    }

    /** @return array<string, mixed> */
    private function account(string $contract = 'fbtest-1', string $email = 'erika@muster.test', bool $approved = true, array $extra = []): array
    {
        return [
            'contract' => $contract,
            'customer' => ['name' => 'Erika Muster', 'organization' => 'Trattoria Muster', 'email' => $email, 'customer_type' => 'company', 'lang' => 'de'],
            'tariff' => ['code' => 'starter', 'label' => 'Starter', 'kind' => 'subscription', 'limits' => ['max_locations' => 1, 'max_tables' => 15], 'free_place' => false, 'ends_at' => null],
            'approved' => $approved,
            'links' => ['admin' => 'https://panel.example.test/admin/order/manage/1'],
        ] + $extra;
    }

    private function tenant(string $contract = 'fbtest-1'): Tenant
    {
        return Tenant::where('contract_ref', $contract)->firstOrFail();
    }

    // --- Signatur und Schutz ---

    public function test_info_beschreibt_den_dienst(): void
    {
        $this->api('GET', '/info')->assertOk()->assertExactJson([
            'version' => '1',
            'service' => 'swayy',
            'app_version' => config('version.number'),
            'units' => [
                ['key' => 'max_locations', 'label' => 'Standorte', 'kind' => 'limit'],
                ['key' => 'max_tables', 'label' => 'Tische', 'kind' => 'limit'],
            ],
            'capabilities' => ['adopt', 'check_only', 'force', 'login_link'],
        ]);
    }

    public function test_ohne_schluessel_ist_die_schnittstelle_aus(): void
    {
        config(['provisioning.keys' => []]);
        $this->api('GET', '/info')->assertStatus(404)->assertJsonPath('error.code', 'interface_off');
    }

    public function test_falscher_schluessel_wird_abgelehnt_und_zweiter_schluessel_gilt(): void
    {
        $this->api('GET', '/info', null, 'falsch-0123456789abcdef0123456789abcdef')->assertStatus(401)->assertJsonPath('error.code', 'signature');

        config(['provisioning.keys' => [self::KEY, self::SECOND_KEY]]);
        $this->api('GET', '/info', null, self::SECOND_KEY)->assertOk();
    }

    public function test_alter_und_kuenftiger_zeitstempel_werden_abgelehnt(): void
    {
        $this->api('GET', '/info', null, null, [], time() - 301)->assertStatus(401)->assertJsonPath('error.code', 'timestamp');
        $this->api('GET', '/info', null, null, [], time() + 301)->assertStatus(401)->assertJsonPath('error.code', 'timestamp');

        config(['provisioning.max_skew_seconds' => 600]);
        $this->api('GET', '/info', null, null, [], time() - 500)->assertOk();
    }

    public function test_veraenderter_koerper_passt_nicht_zur_signatur(): void
    {
        $body = json_encode($this->account());
        $stamp = (string) time();
        $this->call('POST', '/provisioning/v1/accounts', [], [], [], [
            'HTTP_X_PROVISIONING_TIMESTAMP' => $stamp,
            'HTTP_X_PROVISIONING_SIGNATURE' => 'v1='.Signature::request(self::KEY, $stamp, 'POST', '/provisioning/v1/accounts', $body),
            'HTTP_IDEMPOTENCY_KEY' => 'k1',
            'CONTENT_TYPE' => 'application/json',
        ], str_replace('Erika', 'Mallory', (string) $body))->assertStatus(401)->assertJsonPath('error.code', 'signature');
        $this->assertSame(0, Tenant::count());
    }

    public function test_fehlende_koepfe_und_drossel(): void
    {
        config(['provisioning.max_failures' => 2]);
        $this->call('GET', '/provisioning/v1/info')->assertStatus(401)->assertJsonPath('error.code', 'signature_missing');
        $this->call('GET', '/provisioning/v1/info')->assertStatus(401);
        $this->api('GET', '/info')->assertStatus(429)->assertJsonPath('error.code', 'throttled');
    }

    public function test_netzsperre(): void
    {
        config(['provisioning.allowed_networks' => ['10.20.0.0/16']]);
        $this->api('GET', '/info')->assertStatus(403)->assertJsonPath('error.code', 'network');

        config(['provisioning.allowed_networks' => ['127.0.0.0/8']]);
        $this->api('GET', '/info')->assertOk();
    }

    // --- Idempotenz ---

    public function test_wiederholung_liefert_dieselbe_antwort_ohne_zweites_konto(): void
    {
        $kopf = ['Idempotency-Key' => 'erste-kennung'];
        $erste = $this->api('POST', '/accounts', $this->account(), null, $kopf)->assertCreated();
        $zweite = $this->api('POST', '/accounts', $this->account(), null, $kopf)->assertCreated();

        $this->assertSame($erste->json(), $zweite->json());
        $this->assertSame(1, Tenant::count());
        Mail::assertSent(ProvisionedWelcomeMail::class, 1);
    }

    public function test_andere_anfrage_mit_derselben_kennung_gibt_409(): void
    {
        $kopf = ['Idempotency-Key' => 'erste-kennung'];
        $this->api('POST', '/accounts', $this->account(), null, $kopf)->assertCreated();
        $this->api('POST', '/accounts', $this->account('fbtest-2', 'zweite@muster.test'), null, $kopf)
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_mismatch');
    }

    public function test_abgelehnter_aufruf_gelingt_spaeter_mit_derselben_kennung(): void
    {
        $this->api('POST', '/accounts', $this->account(), null, ['Idempotency-Key' => 'a1'])->assertCreated();
        $kopf = ['Idempotency-Key' => 'freigabe-1'];
        $this->api('POST', '/accounts/fbtest-9/approve', [], null, $kopf)->assertStatus(404);
        $this->assertSame(0, DB::table('provisioning_requests')->where('idempotency_key', 'freigabe-1')->count());
    }

    public function test_aendernder_aufruf_ohne_kennung_und_kein_objekt(): void
    {
        $body = '{}';
        $stamp = (string) time();
        $this->call('POST', '/provisioning/v1/accounts', [], [], [], [
            'HTTP_X_PROVISIONING_TIMESTAMP' => $stamp,
            'HTTP_X_PROVISIONING_SIGNATURE' => 'v1='.Signature::request(self::KEY, $stamp, 'POST', '/provisioning/v1/accounts', $body),
        ], $body)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key');

        $this->api('POST', '/accounts', null, null, [], null, '[]')->assertStatus(400)->assertJsonPath('error.code', 'invalid_json');
    }

    // --- Anlegen und Übernehmen ---

    public function test_neues_konto_mit_freigabe(): void
    {
        $antwort = $this->api('POST', '/accounts', $this->account())->assertCreated();
        $betrieb = $this->tenant();

        $antwort->assertExactJson(['account' => (string) $betrieb->id, 'contract' => 'fbtest-1', 'state' => 'active']);
        $this->assertSame('Trattoria Muster', $betrieb->name);
        $this->assertSame('starter', $betrieb->plan->key);
        $this->assertNull($betrieb->trial_ends_at);
        $this->assertNotNull($betrieb->approved_at);
        $this->assertSame('https://panel.example.test/admin/order/manage/1', $betrieb->contract_url);
        $inhaber = User::where('email', 'erika@muster.test')->firstOrFail();
        $this->assertSame($inhaber->id, $betrieb->owner_user_id);
        $this->assertNotNull($inhaber->email_verified_at);
        $this->assertSame('tenant_owner', $inhaber->membershipFor($betrieb)?->role);
        $this->assertSame(1, Location::withoutGlobalScopes()->where('tenant_id', $betrieb->id)->count());
        Mail::assertSent(ProvisionedWelcomeMail::class, fn (ProvisionedWelcomeMail $m) => $m->hasTo('erika@muster.test') && ! $m->waitsForApproval);
    }

    public function test_neues_konto_ohne_freigabe_wartet_und_bucht_nicht(): void
    {
        $this->api('POST', '/accounts', $this->account(approved: false))->assertCreated()->assertJsonPath('state', 'pending_approval');
        $betrieb = $this->tenant();

        $this->get('/'.$betrieb->slug)->assertNotFound();
        Mail::assertSent(ProvisionedWelcomeMail::class, fn (ProvisionedWelcomeMail $m) => $m->waitsForApproval);
    }

    public function test_ende_der_probe_behaelt_seinen_zeitpunkt(): void
    {
        $konto = $this->account();
        $konto['tariff']['ends_at'] = '2026-11-07T23:59:59+01:00';
        $this->api('POST', '/accounts', $konto)->assertCreated();

        $this->assertSame('2026-11-07T22:59:59+00:00', $this->tenant()->tariff_ends_at->utc()->toIso8601String());
        $this->api('GET', '/accounts/fbtest-1')->assertJsonPath('tariff.ends_at', \Carbon\CarbonImmutable::parse('2026-11-07T22:59:59Z')->setTimezone(config('app.timezone'))->toIso8601String());
    }

    public function test_vertrag_doppelt_gibt_contract_exists(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $this->api('POST', '/accounts', $this->account('fbtest-1', 'anders@muster.test'))->assertStatus(409)->assertJsonPath('error.code', 'contract_exists');
    }

    public function test_vorhandene_adresse_und_uebernahme(): void
    {
        $alt = Tenant::factory()->create(['name' => 'Altes Haus', 'trial_ends_at' => now()->addDays(3)]);
        $user = User::factory()->create(['email' => 'erika@muster.test']);
        TenantUser::create(['tenant_id' => $alt->id, 'user_id' => $user->id, 'role' => 'tenant_owner', 'all_locations' => true]);

        $this->api('POST', '/accounts', $this->account())->assertStatus(409)
            ->assertJsonPath('error.code', 'account_exists')->assertJsonPath('error.details.organization', 'Altes Haus');

        $this->api('POST', '/accounts', $this->account(extra: ['adopt' => true]))->assertCreated()->assertJsonPath('account', (string) $alt->id);
        $alt->refresh();
        $this->assertSame('fbtest-1', $alt->contract_ref);
        $this->assertNull($alt->trial_ends_at);
        $this->assertSame($user->id, $alt->owner_user_id);
        Mail::assertNotSent(ProvisionedWelcomeMail::class);
    }

    public function test_uebernahme_bei_mehreren_betrieben_und_betreiber(): void
    {
        $user = User::factory()->create(['email' => 'erika@muster.test']);
        foreach (['Eins', 'Zwei'] as $name) {
            $t = Tenant::factory()->create(['name' => $name]);
            TenantUser::create(['tenant_id' => $t->id, 'user_id' => $user->id, 'role' => 'tenant_owner', 'all_locations' => true]);
        }
        $this->api('POST', '/accounts', $this->account(extra: ['adopt' => true]))->assertStatus(409)->assertJsonPath('error.code', 'ambiguous');

        $admin = User::factory()->create(['email' => 'betreiber@swayy.test']);
        $admin->forceFill(['saas_role' => 'owner'])->save();
        $this->api('POST', '/accounts', $this->account('fbtest-3', 'betreiber@swayy.test', extra: ['adopt' => true]))->assertStatus(409)->assertJsonPath('error.code', 'not_adoptable');
    }

    public function test_eingaben_werden_geprueft(): void
    {
        $falsch = $this->account();
        $falsch['tariff']['limits'] = ['max_rooms' => 3];
        $this->api('POST', '/accounts', $falsch)->assertStatus(400)->assertJsonPath('error.code', 'unknown_unit');

        $falsch = $this->account();
        $falsch['tariff']['limits']['max_tables'] = 0;
        $this->api('POST', '/accounts', $falsch)->assertStatus(400)->assertJsonPath('error.code', 'invalid_limit');

        $falsch = $this->account();
        $falsch['tariff']['ends_at'] = '2027-09-30T23:59:59';
        $this->api('POST', '/accounts', $falsch)->assertStatus(400)->assertJsonPath('error.code', 'invalid_ends_at');

        $falsch = $this->account();
        $falsch['customer']['customer_type'] = 'verein';
        $this->api('POST', '/accounts', $falsch)->assertStatus(400)->assertJsonPath('error.code', 'invalid_customer_type');

        $this->api('POST', '/accounts', ['contract' => 'fb 1'] + $this->account())->assertStatus(400)->assertJsonPath('error.code', 'invalid_contract');
        $this->assertSame(0, Tenant::count());
    }

    // --- Stand, Zustände ---

    public function test_stand_nennt_tarif_grenzen_und_verbrauch(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $this->api('GET', '/accounts/fbtest-1')->assertOk()
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('tariff.code', 'starter')
            ->assertJsonPath('tariff.free_place', false)
            ->assertJsonPath('limits.max_tables', 15)
            ->assertJsonPath('usage.max_locations', 1)
            ->assertJsonPath('owner', 'erika@muster.test');

        $this->api('GET', '/accounts/fbtest-99')->assertStatus(404)->assertJsonPath('error.code', 'unknown_contract');
    }

    public function test_freigabe_oeffnet_einen_wartenden_betrieb(): void
    {
        $this->api('POST', '/accounts', $this->account(approved: false))->assertCreated();
        $this->api('POST', '/accounts/fbtest-1/approve', [])->assertOk()->assertJsonPath('state', 'active');
        Mail::assertSent(ProvisionedApprovedMail::class, 1);

        $this->api('POST', '/accounts/fbtest-1/approve', [])->assertOk()->assertJsonPath('state', 'active');
        Mail::assertSent(ProvisionedApprovedMail::class, 1);
    }

    public function test_zustaende_im_wartenden_betrieb_schalten_nie_frei(): void
    {
        $this->api('POST', '/accounts', $this->account(approved: false))->assertCreated();

        $this->api('POST', '/accounts/fbtest-1/suspend', ['reason' => 'operator'])->assertJsonPath('state', 'suspended');
        $this->api('POST', '/accounts/fbtest-1/unsuspend', [])->assertJsonPath('state', 'pending_approval');
        $this->api('POST', '/accounts/fbtest-1/cancel', ['reason' => 'customer'])->assertJsonPath('state', 'cancelled');
        $this->api('POST', '/accounts/fbtest-1/uncancel', [])->assertJsonPath('state', 'pending_approval');
    }

    public function test_zustaende_im_aktiven_betrieb(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();

        $this->api('POST', '/accounts/fbtest-1/suspend', ['reason' => 'operator'])->assertJsonPath('state', 'suspended');
        $this->api('POST', '/accounts/fbtest-1/cancel', ['reason' => 'contract_ended'])->assertJsonPath('state', 'cancelled');
        $this->api('POST', '/accounts/fbtest-1/suspend', ['reason' => 'operator'])->assertStatus(409)->assertJsonPath('error.code', 'state');
        $this->api('POST', '/accounts/fbtest-1/unsuspend', [])->assertStatus(409);
        $this->api('POST', '/accounts/fbtest-1/approve', [])->assertStatus(409);
        $this->api('POST', '/accounts/fbtest-1/uncancel', [])->assertJsonPath('state', 'suspended');
        $this->assertSame('operator', $this->tenant()->state_reason);
        $this->api('POST', '/accounts/fbtest-1/unsuspend', [])->assertJsonPath('state', 'active');

        $this->api('POST', '/accounts/fbtest-1/cancel', ['reason' => 'nix'])->assertStatus(400)->assertJsonPath('error.code', 'invalid_reason');
        $this->api('POST', '/accounts/fbtest-1/explode', [])->assertStatus(404)->assertJsonPath('error.code', 'unknown_action');
    }

    public function test_gesperrter_betrieb_sieht_die_sperrseite(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $betrieb = $this->tenant();
        $inhaber = User::findOrFail($betrieb->owner_user_id);
        $this->api('POST', '/accounts/fbtest-1/suspend', ['reason' => 'operator'])->assertOk();

        $this->actingAs($inhaber)->get('/admin')->assertStatus(403)->assertSee('ist gesperrt', false)->assertSee('https://panel.example.test', false);
    }

    // --- Tarif ---

    public function test_tarif_pruefen_setzen_und_erzwingen(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $betrieb = $this->tenant();
        $raum = Room::factory()->create(['tenant_id' => $betrieb->id, 'location_id' => Location::withoutGlobalScopes()->where('tenant_id', $betrieb->id)->value('id')]);
        RestaurantTable::factory()->count(3)->create(['room_id' => $raum->id]);

        $klein = ['code' => 'trial', 'label' => 'Probe', 'kind' => 'trial', 'limits' => ['max_locations' => 1, 'max_tables' => 2]];
        $this->api('PUT', '/accounts/fbtest-1/tariff', ['tariff' => $klein, 'check_only' => true])->assertStatus(422)
            ->assertJsonPath('error.code', 'does_not_fit')
            ->assertJsonPath('error.details.problems.0', ['unit' => 'max_tables', 'used' => 3, 'allowed' => 2]);
        $this->api('PUT', '/accounts/fbtest-1/tariff', ['tariff' => $klein])->assertStatus(422);

        $gross = ['code' => 'professional', 'label' => 'Professional', 'kind' => 'subscription', 'limits' => ['max_locations' => 1, 'max_tables' => null]];
        $this->api('PUT', '/accounts/fbtest-1/tariff', ['tariff' => $gross, 'check_only' => true])->assertOk()->assertExactJson(['fits' => true]);
        $this->assertSame('starter', $this->tenant()->tariff_code);

        $this->api('PUT', '/accounts/fbtest-1/tariff', ['tariff' => $klein, 'force' => true])->assertOk()->assertExactJson(['fits' => false, 'tariff' => 'trial']);
        $betrieb = $this->tenant();
        $this->assertSame(2, $betrieb->limit('max_tables'));
        $this->assertSame('trial', $betrieb->plan->key);

        $this->api('PUT', '/accounts/fbtest-1/tariff', ['tariff' => $gross])->assertOk();
        $this->assertNull($this->tenant()->limit('max_tables'));
    }

    // --- Anmeldelink und Rückweg ---

    public function test_anmeldelink_meldet_den_inhaber_einmal_an(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $url = $this->api('POST', '/accounts/fbtest-1/login-link', [])->assertOk()->json('url');
        $this->assertStringStartsWith(url('/anmelden/'), $url);

        $pfad = parse_url($url, PHP_URL_PATH);
        $this->get($pfad)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs(User::where('email', 'erika@muster.test')->first());

        auth()->logout();
        $this->get($pfad)->assertOk()->assertSee('gilt nicht mehr', false);
        $this->assertGuest();
    }

    public function test_anmeldelink_laeuft_ab_und_fehlt_bei_gesperrtem_betrieb(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        config(['provisioning.login_link_seconds' => 30]);
        $pfad = parse_url($this->api('POST', '/accounts/fbtest-1/login-link', [])->json('url'), PHP_URL_PATH);
        $this->travel(31)->seconds();
        $this->get($pfad)->assertOk()->assertSee('gilt nicht mehr', false);
        $this->travelBack();

        $this->api('POST', '/accounts/fbtest-1/suspend', ['reason' => 'operator'])->assertOk();
        $this->api('POST', '/accounts/fbtest-1/login-link', [])->assertStatus(409)->assertJsonPath('error.code', 'closed');
    }

    public function test_rueckweg_ins_panel_fuer_den_inhaber(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $betrieb = $this->tenant();
        $inhaber = User::findOrFail($betrieb->owner_user_id);

        $ziel = $this->actingAs($inhaber)->get('/admin/tarif')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://panel.example.test/saas/zurueck?', (string) $ziel);
        parse_str((string) parse_url((string) $ziel, PHP_URL_QUERY), $q);
        $this->assertSame('swayy', $q['service']);
        $this->assertSame('fbtest-1', $q['contract']);
        $this->assertSame(Signature::back(self::KEY, $q['ts'], $q['nonce'], 'swayy', 'fbtest-1'), $q['sig']);

        $this->actingAs($inhaber)->get(route('admin.billing.show'))->assertRedirect(route('admin.panel.back'));

        $mitglied = User::factory()->create();
        TenantUser::create(['tenant_id' => $betrieb->id, 'user_id' => $mitglied->id, 'role' => 'tenant_admin', 'all_locations' => true]);
        $mitglied->forceFill(['current_tenant_id' => $betrieb->id])->save();
        $this->actingAs($mitglied)->get('/admin/tarif')->assertRedirect('https://panel.example.test');
    }

    // --- Löschen ---

    public function test_loeschen_nur_nach_kuendigung(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $id = $this->tenant()->id;

        $this->api('DELETE', '/accounts/fbtest-1')->assertStatus(409)->assertJsonPath('error.code', 'not_cancelled');
        $this->api('POST', '/accounts/fbtest-1/cancel', ['reason' => 'customer'])->assertOk();
        $this->api('DELETE', '/accounts/fbtest-1')->assertNoContent();

        $this->assertNull(Tenant::withTrashed()->find($id));
        $this->api('GET', '/accounts/fbtest-1')->assertStatus(404);
    }

    // --- Grenzen und alte Wege ---

    public function test_grenzen_aus_dem_vertrag_gehen_vor_den_plan(): void
    {
        $this->api('POST', '/accounts', $this->account())->assertCreated();
        $betrieb = $this->tenant();
        $betrieb->forceFill(['contract_limits' => ['max_locations' => 4, 'max_tables' => null]])->save();

        $this->assertSame(4, $betrieb->fresh()->limit('max_locations'));
        $this->assertNull($betrieb->fresh()->limit('max_tables'));
        $this->assertSame(20, $betrieb->fresh()->limit('max_users'));
    }

    public function test_registrierung_leitet_ins_panel_sobald_eingetragen(): void
    {
        $this->get('/register')->assertOk();

        config(['provisioning.panel_order_url' => 'https://panel.example.test/bestellen/swayy']);
        $this->get('/register')->assertRedirect('https://panel.example.test/bestellen/swayy');
        $this->post('/register', ['restaurant_name' => 'X'])->assertRedirect('https://panel.example.test/bestellen/swayy');
    }
}

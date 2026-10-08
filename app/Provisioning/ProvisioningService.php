<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Mail\ProvisionedApprovedMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Services\TenantDeletionService;
use App\Services\TenantSignupService;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Was das Kundenpanel von swayy verlangt (Skill kundenpanel-saas, aufrufe.md).
 *
 * Jede Methode prueft ihren Aufruf, aendert ohne festzuschreiben und liefert
 * ein Outcome. Der Controller schreibt es zusammen mit der Aufrufkennung fest
 * und fuehrt erst danach `afterCommit` aus (Mails).
 */
final class ProvisioningService
{
    public const VERSION = '1';

    public const LOCALES = ['de', 'en'];

    public const TARIFF_KINDS = ['trial', 'subscription', 'one_time'];

    public const CUSTOMER_TYPES = ['company', 'personal'];

    public const LIMIT_MAX = 1_000_000;

    /** Wer im Audit als Ausloeser steht. */
    private const ACTOR = 'Kundenbereich';

    public function __construct(
        private readonly TenantSignupService $signup,
        private readonly TenantDeletionService $deletion,
        private readonly PlanLimitService $limits,
        private readonly LoginLinks $loginLinks,
        private readonly AuditLogger $audit,
    ) {}

    public function info(): Outcome
    {
        return new Outcome(200, [
            'version' => self::VERSION,
            'service' => (string) config('provisioning.service_code'),
            'app_version' => (string) config('version.number', ''),
            'units' => array_map(
                fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'kind' => 'limit'],
                array_keys(Tenant::CONTRACT_UNITS),
                array_values(Tenant::CONTRACT_UNITS),
            ),
            'capabilities' => ['adopt', 'check_only', 'force', 'login_link'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createAccount(array $payload): Outcome
    {
        $vertrag = $this->contract($payload['contract'] ?? null);
        $kunde = $this->customer($payload['customer'] ?? null);
        $tarif = $this->tariff($payload['tariff'] ?? null);
        $links = is_array($payload['links'] ?? null) ? $payload['links'] : [];
        $verwaltung = $this->link($links['admin'] ?? null);
        $freigegeben = ($payload['approved'] ?? null) === true;

        if (Tenant::withTrashed()->where('contract_ref', $vertrag)->exists()) {
            throw new ProvisioningError(409, 'contract_exists', "Zum Vertrag {$vertrag} gibt es schon einen Betrieb. Seinen Stand liefert GET /accounts/{$vertrag}.");
        }

        $nach = [];
        $benutzer = User::whereRaw('LOWER(email) = ?', [$kunde['email']])->first();
        if ($benutzer !== null) {
            $betrieb = $this->adoptable($benutzer, ($payload['adopt'] ?? null) === true);
            $uebernommen = true;
        } else {
            [$betrieb, $benutzer] = $this->signup->create(
                $kunde['organization'],
                $kunde['name'],
                $kunde['email'],
                null,
                // Ohne Plan gleichen Namens bekommt der Betrieb die Funktionen der Probe.
                $this->planFor($tarif['code']) ?? Plan::where('key', 'trial')->first(),
                ['status' => AccountState::PENDING, 'default_locale' => $kunde['lang']],
            );
            // Das Panel hat die Adresse bestaetigt, bevor es bestellt.
            $benutzer->forceFill(['email_verified_at' => now()])->save();
            $uebernommen = false;
        }

        $betrieb->forceFill([
            'contract_ref' => $vertrag,
            'contract_url' => $verwaltung,
            'owner_user_id' => $benutzer->id,
            // Die Probe regelt ab jetzt das Panel; die eigene Frist entfaellt.
            'trial_ends_at' => null,
        ]);
        $this->applyTariff($betrieb, $tarif);
        AccountState::start($betrieb, $freigegeben);
        $betrieb->save();

        $this->log('provisioning.create', $betrieb, ['tariff' => $tarif['code'], 'approved' => $freigegeben, 'adopted' => $uebernommen]);

        if (! $uebernommen) {
            $nach[] = fn () => $this->sendWelcome($benutzer, $betrieb);
        }

        return new Outcome(201, ['account' => (string) $betrieb->id, 'contract' => $vertrag, 'state' => $betrieb->status], $nach);
    }

    public function status(string $contract): Outcome
    {
        $betrieb = $this->tenant($contract);
        $inhaber = $betrieb->owner_user_id !== null ? User::find($betrieb->owner_user_id) : null;
        $grenzen = [];
        $verbrauch = [];
        foreach (array_keys(Tenant::CONTRACT_UNITS) as $einheit) {
            $grenzen[$einheit] = $betrieb->limit($einheit);
            $verbrauch[$einheit] = $this->limits->currentUsage($betrieb, $einheit);
        }

        return new Outcome(200, [
            'contract' => $betrieb->contract_ref,
            'account' => (string) $betrieb->id,
            'state' => $betrieb->status,
            'state_reason' => $betrieb->state_reason,
            'approved_at' => $betrieb->approved_at?->toIso8601String(),
            'tariff' => [
                'code' => $betrieb->tariff_code,
                'label' => $betrieb->tariff_label,
                'kind' => $betrieb->tariff_kind,
                'free_place' => (bool) $betrieb->free_place,
                'ends_at' => $betrieb->tariff_ends_at?->toIso8601String(),
            ],
            'limits' => $grenzen,
            'usage' => $verbrauch,
            'owner' => $inhaber?->email,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function approve(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        $aufgegangen = AccountState::approve($betrieb);
        $betrieb->save();
        $this->log('provisioning.approve', $betrieb);

        $nach = $aufgegangen ? [fn () => $this->sendApproved($betrieb)] : [];

        return new Outcome(200, ['state' => $betrieb->status], $nach);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function suspend(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        $grund = $this->reason($payload, AccountState::SUSPEND_REASONS);
        AccountState::suspend($betrieb, $grund);
        $betrieb->save();
        $this->log('provisioning.suspend', $betrieb, ['reason' => $grund]);

        return new Outcome(200, ['state' => $betrieb->status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unsuspend(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        AccountState::unsuspend($betrieb);
        $betrieb->save();
        $this->log('provisioning.unsuspend', $betrieb);

        return new Outcome(200, ['state' => $betrieb->status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancel(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        $grund = $this->reason($payload, AccountState::CANCEL_REASONS);
        AccountState::cancel($betrieb, $grund);
        $betrieb->save();
        $this->log('provisioning.cancel', $betrieb, ['reason' => $grund]);

        return new Outcome(200, ['state' => $betrieb->status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function uncancel(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        AccountState::uncancel($betrieb);
        $betrieb->save();
        $this->log('provisioning.uncancel', $betrieb);

        return new Outcome(200, ['state' => $betrieb->status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setTariff(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        $tarif = $this->tariff($payload['tariff'] ?? null);
        $probleme = $this->limitProblems($betrieb, $tarif['limits']);
        $nurPruefen = ($payload['check_only'] ?? null) === true;

        if ($probleme !== [] && ($nurPruefen || ($payload['force'] ?? null) !== true)) {
            throw new ProvisioningError(422, 'does_not_fit', self::problemsMessage($probleme), ['problems' => $probleme]);
        }
        if ($nurPruefen) {
            return new Outcome(200, ['fits' => true]);
        }

        $this->applyTariff($betrieb, $tarif);
        $betrieb->save();
        $this->log('provisioning.tariff', $betrieb, ['tariff' => $tarif['code'], 'forced' => $probleme !== []]);

        return new Outcome(200, ['fits' => $probleme === [], 'tariff' => $tarif['code']]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function loginLink(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        $inhaber = $betrieb->owner_user_id !== null ? User::find($betrieb->owner_user_id) : null;
        if ($inhaber === null || $inhaber->membershipFor($betrieb) === null) {
            throw new ProvisioningError(409, 'no_owner', "„{$betrieb->name}“ hat keinen Zugang des Inhabers mehr. Der Betreiber von Swayy hilft unter Plattform.");
        }
        if (! AccountState::isOpen($betrieb)) {
            throw new ProvisioningError(409, 'closed', "„{$betrieb->name}“ ist ".AccountState::label($betrieb).'. Ein Anmeldelink geht wieder, sobald der Betrieb offen ist.');
        }

        $link = $this->loginLinks->issue($inhaber, $betrieb);

        return new Outcome(200, ['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteAccount(string $contract, array $payload): Outcome
    {
        $betrieb = $this->tenant($contract);
        if ($betrieb->status !== AccountState::CANCELLED) {
            throw new ProvisioningError(409, 'not_cancelled', "„{$betrieb->name}“ ist ".AccountState::label($betrieb).". Gelöscht wird ein gekündigter Betrieb; kündige zuerst mit POST /accounts/{$contract}/cancel.");
        }

        // Das Protokoll des Betriebs geht mit; die Spur bleibt im Protokoll der Plattform.
        Log::info('Bereitstellung: Betrieb gelöscht.', ['contract' => $contract, 'tenant_id' => $betrieb->id, 'name' => $betrieb->name]);
        $this->audit->log('provisioning.delete', null, null, null, ['contract' => $contract, 'name' => $betrieb->name, 'actor' => self::ACTOR], null, null);
        $this->deletion->delete($betrieb);

        return new Outcome(204, null);
    }

    // ------------------------------------------------------------------
    // Pruefungen
    // ------------------------------------------------------------------

    private function contract(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,60}$/', $value) !== 1) {
            throw new ProvisioningError(400, 'invalid_contract', 'Die Kennung des Vertrags fehlt oder enthält andere Zeichen als Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich (höchstens 60).');
        }

        return $value;
    }

    public function tenant(string $contract): Tenant
    {
        $betrieb = Tenant::where('contract_ref', $this->contract($contract))->first();
        if ($betrieb === null) {
            throw new ProvisioningError(404, 'unknown_contract', "Zum Vertrag {$contract} gibt es in Swayy keinen Betrieb. Leg ihn mit POST /accounts an.");
        }

        return $betrieb;
    }

    /**
     * @return array{name: string, organization: string, email: string, customer_type: string, lang: string}
     */
    private function customer(mixed $data): array
    {
        if (! is_array($data)) {
            throw new ProvisioningError(400, 'invalid_customer', 'Die Angaben zum Kunden fehlen. Schick customer mit name, organization, email und customer_type.');
        }
        $mail = strtolower(trim((string) ($data['email'] ?? '')));
        if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false || strlen($mail) > 100) {
            throw new ProvisioningError(400, 'invalid_email', 'Die Mailadresse des Kunden fehlt, ist ungültig oder länger als 100 Zeichen.');
        }
        if (! in_array($data['customer_type'] ?? null, self::CUSTOMER_TYPES, true)) {
            throw new ProvisioningError(400, 'invalid_customer_type', 'customer_type muss company oder personal sein.');
        }
        $sprache = strtolower((string) ($data['lang'] ?? 'de'));

        return [
            'name' => $this->text($data, 'name', 'Der Name des Kunden', 120),
            'organization' => $this->text($data, 'organization', 'Der Name des Betriebs', 120),
            'email' => $mail,
            'customer_type' => (string) $data['customer_type'],
            'lang' => in_array($sprache, self::LOCALES, true) ? $sprache : 'de',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key, string $label, int $longest): string
    {
        $wert = is_string($data[$key] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $data[$key])) : '';
        if ($wert === '' || mb_strlen($wert) > $longest) {
            throw new ProvisioningError(400, "invalid_{$key}", "{$label} fehlt oder ist länger als {$longest} Zeichen.");
        }

        return $wert;
    }

    /**
     * @return array{code: string, label: string, kind: string, limits: array<string, int|null>, free_place: bool, ends_at: CarbonImmutable|null}
     */
    private function tariff(mixed $data): array
    {
        if (! is_array($data)) {
            throw new ProvisioningError(400, 'invalid_tariff', 'Der Tarif fehlt. Schick tariff mit code, label, kind und limits.');
        }
        $code = $data['code'] ?? null;
        if (! is_string($code) || preg_match('/^[a-z0-9_-]{1,40}$/', $code) !== 1) {
            throw new ProvisioningError(400, 'invalid_tariff_code', 'Der Tarifcode fehlt oder enthält andere Zeichen als a–z, 0–9, _ und - (höchstens 40).');
        }
        if (! in_array($data['kind'] ?? null, self::TARIFF_KINDS, true)) {
            throw new ProvisioningError(400, 'invalid_tariff_kind', 'kind muss trial, subscription oder one_time sein.');
        }
        $grenzen = is_array($data['limits'] ?? null) ? $data['limits'] : [];
        $unbekannt = array_diff(array_keys($grenzen), array_keys(Tenant::CONTRACT_UNITS));
        if ($unbekannt !== []) {
            throw new ProvisioningError(400, 'unknown_unit', 'Diese Einheiten kennt Swayy nicht: '.implode(', ', $unbekannt).'. Bekannt sind '.implode(', ', array_keys(Tenant::CONTRACT_UNITS)).'.');
        }
        $geprueft = [];
        foreach (array_keys(Tenant::CONTRACT_UNITS) as $einheit) {
            $wert = $grenzen[$einheit] ?? null;
            if ($wert !== null && (! is_int($wert) || $wert < 1 || $wert > self::LIMIT_MAX)) {
                throw new ProvisioningError(400, 'invalid_limit', "Die Grenze für {$einheit} muss null oder eine ganze Zahl zwischen 1 und 1.000.000 sein.");
            }
            $geprueft[$einheit] = $wert;
        }

        return [
            'code' => $code,
            'label' => $this->text($data, 'label', 'Die Bezeichnung des Tarifs', 200),
            'kind' => (string) $data['kind'],
            'limits' => $geprueft,
            'free_place' => ($data['free_place'] ?? null) === true,
            'ends_at' => $this->endsAt($data['ends_at'] ?? null),
        ];
    }

    private function endsAt(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        $fehler = new ProvisioningError(400, 'invalid_ends_at', 'ends_at braucht einen Zeitpunkt nach ISO 8601 mit Zeitzone, etwa 2027-09-30T23:59:59+02:00.');
        if (! is_string($value) || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            throw $fehler;
        }
        try {
            // In die Zeitzone der App: Laravel speichert die Uhrzeit ohne Umrechnung,
            // aus 23:59:59+01:00 wuerde sonst 23:59:59 in UTC, eine Stunde zu spaet.
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            throw $fehler;
        }
    }

    private function link(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! str_starts_with($value, 'https://') || preg_match('/\s/', $value) === 1 || strlen($value) > 500) {
            throw new ProvisioningError(400, 'invalid_link', 'links.admin braucht eine vollständige Adresse mit https://, höchstens 500 Zeichen.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private function reason(array $payload, array $allowed): string
    {
        $grund = $payload['reason'] ?? null;
        if (! is_string($grund) || ! in_array($grund, $allowed, true)) {
            throw new ProvisioningError(400, 'invalid_reason', 'reason muss einer dieser Werte sein: '.implode(', ', $allowed).'.');
        }

        return $grund;
    }

    /**
     * Der vorhandene Betrieb eines Benutzers, den das Panel uebernehmen darf.
     */
    private function adoptable(User $user, bool $adopt): Tenant
    {
        $eigene = Tenant::query()
            ->whereIn('id', TenantUser::where('user_id', $user->id)->where('role', 'tenant_owner')->pluck('tenant_id'))
            ->orderBy('id')
            ->get();
        $namen = $eigene->pluck('name')->implode(', ');

        if (! $adopt) {
            throw new ProvisioningError(409, 'account_exists', "Zu {$user->email} gibt es in Swayy schon einen Zugang".($namen !== '' ? " (Betrieb: {$namen})" : '').'. Übernimm den Betrieb mit adopt: true oder nimm eine andere Adresse.', ['organization' => $namen]);
        }
        if ($user->isSaasAdmin()) {
            throw new ProvisioningError(409, 'not_adoptable', 'Diese Adresse gehört zum Betreiber von Swayy. Ein Vertrag lässt sich nur mit dem Betrieb eines Kunden verbinden.');
        }

        $frei = $eigene->filter(fn (Tenant $t) => $t->contract_ref === null);
        if ($frei->count() === 0) {
            throw new ProvisioningError(409, 'already_linked', $eigene->isEmpty()
                ? "{$user->email} ist in keinem Betrieb Inhaber. Übernommen wird ein Betrieb, dessen Inhaber diese Adresse hat."
                : "Die Betriebe von {$user->email} hängen schon an einem Vertrag ({$namen}).");
        }
        if ($frei->count() > 1) {
            throw new ProvisioningError(409, 'ambiguous', "{$user->email} ist Inhaber mehrerer Betriebe ohne Vertrag ({$frei->pluck('name')->implode(', ')}). Welcher gemeint ist, klärt der Betreiber von Swayy unter Plattform; danach den Aufruf wiederholen.");
        }

        return $frei->first();
    }

    /**
     * @param  array{code: string, label: string, kind: string, limits: array<string, int|null>, free_place: bool, ends_at: CarbonImmutable|null}  $tarif
     */
    private function applyTariff(Tenant $tenant, array $tarif): void
    {
        $tenant->forceFill([
            'tariff_code' => $tarif['code'],
            'tariff_label' => $tarif['label'],
            'tariff_kind' => $tarif['kind'],
            'free_place' => $tarif['free_place'],
            'tariff_ends_at' => $tarif['ends_at'],
            'contract_limits' => $tarif['limits'],
        ]);
        // Funktionen und uebrige Grenzen kommen vom Plan gleichen Namens, wenn es ihn gibt.
        $plan = $this->planFor($tarif['code']);
        if ($plan !== null) {
            $tenant->plan_id = $plan->id;
        }
    }

    private function planFor(string $code): ?Plan
    {
        return Plan::where('key', $code)->first();
    }

    /**
     * @param  array<string, int|null>  $limits
     * @return list<array{unit: string, used: int, allowed: int}>
     */
    private function limitProblems(Tenant $tenant, array $limits): array
    {
        $probleme = [];
        foreach ($limits as $einheit => $erlaubt) {
            if ($erlaubt === null) {
                continue;
            }
            $genutzt = $this->limits->currentUsage($tenant, $einheit);
            if ($genutzt > $erlaubt) {
                $probleme[] = ['unit' => $einheit, 'used' => $genutzt, 'allowed' => $erlaubt];
            }
        }

        return $probleme;
    }

    /**
     * @param  list<array{unit: string, used: int, allowed: int}>  $problems
     */
    public static function problemsMessage(array $problems): string
    {
        $saetze = array_map(
            fn (array $p) => "{$p['used']} ".Tenant::CONTRACT_UNITS[$p['unit']].", der Tarif erlaubt {$p['allowed']}.",
            $problems,
        );

        return implode(' ', $saetze).' Entferne Standorte oder Tische oder wähle einen größeren Tarif.';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(string $action, Tenant $tenant, array $details = []): void
    {
        $this->audit->log($action, $tenant, null, null, ['contract' => $tenant->contract_ref, 'actor' => self::ACTOR] + $details, null, $tenant->id);
    }

    private function sendWelcome(User $user, Tenant $tenant): void
    {
        $this->sendMail($user, new ProvisionedWelcomeMail(
            $user->name,
            $tenant->name,
            $tenant->status === AccountState::PENDING,
            route('password.request'),
            route('login'),
            (string) config('provisioning.panel_base_url') ?: route('login'),
        ));
    }

    private function sendApproved(Tenant $tenant): void
    {
        $inhaber = $tenant->owner_user_id !== null ? User::find($tenant->owner_user_id) : null;
        if ($inhaber !== null) {
            $this->sendMail($inhaber, new ProvisionedApprovedMail($inhaber->name, $tenant->name, route('login')));
        }
    }

    /**
     * Mails gehen synchron hinaus, wie die uebrigen Kontomails: Sie haengen an
     * keinem Queue-Worker. Ein Fehler steht im Log und haelt die Antwort an das
     * Panel nicht auf; das Konto ist zu diesem Zeitpunkt schon festgeschrieben.
     */
    private function sendMail(User $user, Mailable $mail): void
    {
        try {
            Mail::to($user->email)->send($mail);
        } catch (\Throwable $e) {
            Log::error('Bereitstellung: Mail an den Inhaber ging nicht hinaus. Nächster Schritt: Mailversand prüfen und den Inhaber auf den Kundenbereich hinweisen.', ['user_id' => $user->id, 'mail' => $mail::class, 'error' => $e->getMessage()]);
        }
    }
}

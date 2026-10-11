<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Einstellungen › Verbindungen › API-Schlüssel: Zugang für andere Programme zu
 * den Reservierungen des Betriebs. Gilt für alle Standorte. Der Klartext eines
 * neuen Schlüssels steht genau einmal in der Seite, nach dem Neuladen aus der
 * Sitzung; gespeichert ist nur sein Hash.
 */
class ApiTokenController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();

        return view('admin.api-tokens.index', [
            'tokens' => $this->tenantTokens(),
            'scopes' => $this->scopes($tenant->isSalon()),
            'salon' => $tenant->isSalon(),
            'apiEnabled' => $tenant->hasFeature('api_enabled'),
            'currentUserId' => $request->user()->id,
            // Der Klartext erscheint einmal: Wer ihn aus der Sitzung holt, löscht ihn dort.
            'newToken' => $request->session()->pull('new_token'),
            'nameMax' => (int) config('swayy.api_tokens.name_max'),
        ]);
    }

    /**
     * Die Rechte eines Schlüssels. Die Kennungen sind Teil der Schnittstelle
     * (resources/api/openapi.yaml); Bezeichnung und Satz sind für Menschen.
     * Salons lesen „Termine“ und „Kunden“.
     *
     * @return array<string, array{label: string, help: string}>
     */
    private function scopes(bool $salon): array
    {
        $bookings = $salon ? 'Termine' : 'Reservierungen';
        $guests = $salon ? 'Kunden' : 'Gäste';

        return [
            'reservations:read' => ['label' => $bookings.' lesen', 'help' => "Das Programm sieht {$bookings} mit Zeit, Status und Kontaktdaten."],
            'reservations:write' => ['label' => $bookings.' anlegen und ändern', 'help' => "Das Programm legt neue {$bookings} an und ändert bestehende, etwa durch eine Stornierung."],
            'guests:read' => ['label' => $guests.' lesen', 'help' => "Das Programm sieht die Profile deiner {$guests}."],
            'guests:write' => ['label' => $guests.' anlegen und ändern', 'help' => "Das Programm legt {$guests} an und ändert ihre Profile."],
            'availability:read' => ['label' => 'Freie Zeiten abfragen', 'help' => 'Das Programm fragt ab, wann noch etwas frei ist, etwa für eine Buchungsseite auf deiner Website.'],
            'waitlist:write' => ['label' => 'Warteliste befüllen', 'help' => "Das Programm trägt {$guests} in die Warteliste ein."],
            'events:read' => ['label' => 'Events lesen', 'help' => 'Das Programm sieht deine Events und ihre Buchungen.'],
            'events:write' => ['label' => 'Events anlegen und ändern', 'help' => 'Das Programm legt Events an und ändert sie.'],
            'webhooks:manage' => ['label' => 'Webhooks verwalten', 'help' => 'Das Programm richtet Webhooks ein und löscht sie wieder.'],
            'reports:read' => ['label' => 'Berichte lesen', 'help' => 'Das Programm sieht die Zahlen deiner Berichte.'],
        ];
    }

    /**
     * Alle Token dieses Betriebs, auch die der Kollegen.
     *
     * Wer api_tokens.manage hat, sieht den Bestand des Betriebs und widerruft
     * jeden Token, auch den eines Kollegen, dessen Mitgliedschaft bestehen bleibt.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    private function tenantTokens(): Collection
    {
        $tenantAbility = 'tenant:'.$this->context->tenantId();
        $mitglieder = TenantUser::where('tenant_id', $this->context->tenantId())->pluck('user_id');

        return PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $mitglieder)
            ->with('tokenable:id,name,email')
            ->latest('id')
            ->get()
            ->filter(fn ($t) => in_array($tenantAbility, $t->abilities ?? [], true))
            ->values();
    }

    public function store(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_unless($tenant->hasFeature('api_enabled'), 403, __('Die Schnittstelle ist in deinem Tarif nicht enthalten. Unter „Abo & Rechnungen“ kannst du den Tarif wechseln.'));

        $nameMax = (int) config('swayy.api_tokens.name_max');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.$nameMax],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys($this->scopes($tenant->isSalon())))],
        ], [
            'name.required' => __('Bitte einen Namen eintragen, etwa das Programm, das den Schlüssel nutzt.'),
            'name.string' => __('Der Name muss ein Text sein. Bitte ihn neu eintragen.'),
            'name.max' => __('Der Name darf höchstens :max Zeichen haben.', ['max' => $nameMax]),
            'scopes.required' => __('Bitte mindestens ein Recht anhaken. Für die meisten Programme reicht „Reservierungen lesen“.'),
            'scopes.array' => __('Die Rechte kamen in einer unerwarteten Form an. Bitte die Seite neu laden und noch einmal versuchen.'),
            'scopes.min' => __('Bitte mindestens ein Recht anhaken. Für die meisten Programme reicht „Reservierungen lesen“.'),
            'scopes.*.string' => __('Dieses Recht gibt es nicht. Bitte die Seite neu laden.'),
            'scopes.*.in' => __('Dieses Recht gibt es nicht. Bitte die Seite neu laden.'),
        ]);

        // Tenant binding lives in the token abilities
        $abilities = array_merge(['tenant:'.$this->context->tenantId()], $validated['scopes']);
        $token = $request->user()->createToken($validated['name'], $abilities);

        $this->audit->log('api_token.created', null, null, ['name' => $validated['name'], 'scopes' => $validated['scopes']]);

        // Die Antwort ist JSON (die Seite lädt danach neu) oder eine Weiterleitung; in beiden Fällen holt die nächste
        // Anzeige der Seite den Klartext aus der Sitzung.
        $request->session()->put('new_token', $token->plainTextToken);

        return $this->saved($request, __('API-Schlüssel „:name“ angelegt. Kopiere ihn jetzt; Swayy zeigt ihn nur einmal.', ['name' => $validated['name']]), reload: true);
    }

    public function destroy(Request $request, int $tokenId)
    {
        $token = $this->tenantTokens()->firstWhere('id', $tokenId);
        abort_if($token === null, 404);

        $this->audit->log('api_token.deleted', null, [
            'name' => $token->name,
            'owner_id' => $token->tokenable_id,
        ]);
        $token->delete();

        return $this->saved($request, __('API-Schlüssel „:name“ widerrufen.', ['name' => $token->name]), reload: true);
    }
}

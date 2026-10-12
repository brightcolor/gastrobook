<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\FileSize;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Einstellungen › Betrieb › Standorte: Liste, Anlegen und Bearbeiten in der
 * Seitenleiste, Aktivieren und Deaktivieren. Einzige Stelle für Adresse,
 * Kontakt, Zeitzone und Begrüßungstext eines Standorts; das Logo speichert
 * LocationLogoController.
 */
class LocationController extends Controller
{
    use RespondsToSettingsForms;

    /** Längstes Kürzel vor dem Zähler; die Spalte locations.slug ist varchar(255). */
    private const SLUG_BASE_MAX = 240;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $locations = $tenant->locations()
            ->withCount(['tables', 'rooms'])
            ->orderBy('name')
            ->get();
        $types = array_map('strval', (array) config('swayy.uploads.logo_types'));

        return view('admin.locations.index', [
            'tenant' => $tenant,
            'locations' => $locations,
            'limit' => $tenant->limit('max_locations'),
            'used' => $locations->count(),
            'canAdd' => $this->limits->canAdd($tenant, 'max_locations'),
            'canEditLogo' => $request->user()->canInTenant('tenant.settings.manage', $tenant),
            'canSeeBilling' => $request->user()->canInTenant('billing.manage', $tenant),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('swayy.locations.default_timezone'),
            'fieldLimits' => $this->fieldLimits(),
            'logoAccept' => implode(',', array_map(fn (string $type) => '.'.$type, $types)),
            'logoHelp' => __('Als :types, bis :size.', [
                'types' => LocationLogoController::typeList($types),
                'size' => FileSize::fromKilobytes((int) config('swayy.uploads.logo_max_kb')),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        if (! $this->limits->canAdd($tenant, 'max_locations')) {
            $limit = (int) $tenant->limit('max_locations');

            return $this->failed($request, 'name', trans_choice(
                'Dein Tarif umfasst einen Standort, und der ist schon angelegt.|Dein Tarif umfasst :count Standorte, und alle sind schon angelegt.',
                $limit,
                ['count' => $limit],
            ).' '.($request->user()->canInTenant('billing.manage', $tenant)
                ? __('Für einen weiteren Standort den Tarif unter „Abo & Rechnungen“ wechseln.')
                : __('Für einen weiteren Standort bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.')));
        }

        $validated = $request->validate($this->rules(), $this->messages(), $this->attributes());

        $location = Location::create([
            'tenant_id' => $tenant->id,
            'slug' => $this->uniqueSlug($tenant->id, $validated['name']),
            'is_active' => true,
            'online_booking_enabled' => true,
            ...$this->values($validated),
        ]);
        $location->settings()->create(['tenant_id' => $tenant->id]);

        $this->audit->log('location.created', $location, null, ['name' => $location->name]);

        $message = $tenant->isSalon()
            ? __('Standort „:name“ angelegt. Als Nächstes Öffnungszeiten und Leistungen einrichten.', ['name' => $location->name])
            : __('Standort „:name“ angelegt. Als Nächstes Öffnungszeiten und Tische einrichten.', ['name' => $location->name]);

        if ($request->wantsJson()) {
            return $this->saved($request, $message, reload: true);
        }

        return redirect()->route('admin.locations.index')->with('success', $message);
    }

    public function update(Request $request, Location $location)
    {
        // Route-model binding is tenant-scoped via the global scope; this is
        // belt-and-suspenders against a missing context.
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $values = $this->values($request->validate($this->rules(), $this->messages(), $this->attributes()));
        $old = $location->only(array_keys($values));

        // The slug stays fixed on rename so existing public booking links
        // (/book/{tenant}/{location}) keep working.
        $location->update($values);

        $this->audit->log('location.updated', $location, $old, $values);

        return $this->saved($request, __('Standort „:name“ gespeichert.', ['name' => $location->name]), reload: true);
    }

    public function toggleActive(Request $request, Location $location)
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $tenant = $this->context->tenant();

        // Never deactivate the last active location – the admin area resolves a
        // location from the active set and the public booking would break.
        if ($location->is_active
            && $tenant->locations()->where('is_active', true)->count() <= 1) {
            return $this->failed($request, 'active', __(
                '„:name“ ist der einzige aktive Standort und bleibt aktiv, damit Gäste online buchen können. Lege zuerst einen weiteren Standort an oder aktiviere einen.',
                ['name' => $location->name],
            ));
        }

        $location->update(['is_active' => ! $location->is_active]);

        $this->audit->log('location.'.($location->is_active ? 'activated' : 'deactivated'), $location);

        return $this->saved($request, $location->is_active
            ? __('Standort „:name“ ist wieder aktiv.', ['name' => $location->name])
            : __('Standort „:name“ ist deaktiviert.', ['name' => $location->name]), reload: true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $max = $this->fieldLimits();

        return [
            'name' => ['required', 'string', 'max:'.$max['name']],
            'timezone' => ['required', 'timezone'],
            'phone' => ['nullable', 'string', 'max:'.$max['phone']],
            'email' => ['nullable', 'email:rfc', 'max:'.$max['email']],
            'address_line1' => ['nullable', 'string', 'max:'.$max['address']],
            'postal_code' => ['nullable', 'string', 'max:'.$max['postal_code']],
            'city' => ['nullable', 'string', 'max:'.$max['city']],
            'public_intro' => ['nullable', 'string', 'max:'.$max['intro']],
        ];
    }

    /**
     * Längste Eingaben der Felder in Zeichen (config/swayy.php, locations.*).
     * Die Prüfung beim Speichern und das Attribut maxlength des Formulars
     * lesen dieselben Werte.
     *
     * @return array{name: int, phone: int, email: int, address: int, postal_code: int, city: int, intro: int}
     */
    private function fieldLimits(): array
    {
        return [
            'name' => (int) config('swayy.locations.name_max'),
            'phone' => (int) config('swayy.locations.phone_max'),
            'email' => (int) config('swayy.locations.email_max'),
            'address' => (int) config('swayy.locations.address_max'),
            'postal_code' => (int) config('swayy.locations.postal_code_max'),
            'city' => (int) config('swayy.locations.city_max'),
            'intro' => (int) config('swayy.locations.intro_max'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => __('Bitte einen Namen für den Standort eintragen.'),
            'timezone.required' => __('Bitte eine Zeitzone aus der Liste wählen.'),
            'timezone.timezone' => __('Bitte eine Zeitzone aus der Liste wählen.'),
            'email.email' => __('Die E-Mail-Adresse ist unvollständig. Bitte in der Form name@beispiel.de eintragen.'),
            '*.max' => __(':attribute ist zu lang. Bitte auf höchstens :max Zeichen kürzen.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => __('Name'),
            'phone' => __('Telefon'),
            'email' => __('E-Mail'),
            'address_line1' => __('Straße und Hausnummer'),
            'postal_code' => __('PLZ'),
            'city' => __('Ort'),
            'public_intro' => __('Begrüßung'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
            'public_intro' => $validated['public_intro'] ?? null,
        ];
    }

    private function uniqueSlug(int $tenantId, string $name): string
    {
        // Das Kürzel schreibt „ä“ als „ae“ und kann länger werden als der Name. Die Spalte slug fasst 255 Zeichen;
        // die Kürzung lässt Platz für den Zähler („-2“) und endet nie auf einem Bindestrich.
        $base = rtrim(Str::limit(Str::slug($name), self::SLUG_BASE_MAX, ''), '-') ?: 'standort';
        $slug = $base;
        $i = 1;
        while (Location::withTrashed()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}

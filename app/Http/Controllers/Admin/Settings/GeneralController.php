<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Betrieb › Allgemein: Name, Rechtslinks, Betriebstyp,
 * Markenfarbe, Newsletter-Einwilligung. Gilt für alle Standorte.
 */
class GeneralController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        return view('admin.settings.general', [
            'tenant' => $tenant,
            'brandColor' => $tenant->brand_primary_color ?: config('swayy.ui.default_brand_color'),
        ]);
    }

    /**
     * Stammdaten des Betriebs: Name und Rechtslinks. Die Daten der Standorte
     * (Name, Zeitzone, Adresse, Begrüßung) speichert LocationController.
     */
    public function update(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            // Die Buchungsseite verlangt vom Gast ein Pflichthäkchen auf die
            // Datenschutzhinweise; diese Links führen dorthin.
            'imprint_url' => ['nullable', 'url', 'max:255'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'terms_url' => ['nullable', 'url', 'max:255'],
        ]);

        $tenantValues = [
            'name' => $validated['business_name'],
            'imprint_url' => $validated['imprint_url'] ?? null,
            'privacy_url' => $validated['privacy_url'] ?? null,
            'terms_url' => $validated['terms_url'] ?? null,
        ];
        $old = $tenant->only(array_keys($tenantValues));
        $tenant->update($tenantValues);

        $this->audit->log('tenant.general_updated', $tenant, $old, $tenantValues);

        // reload bei neuem Namen: Seitenleiste und Kopfzeile der Verwaltung
        // zeigen den Betriebsnamen.
        return $this->saved($request, __('Stammdaten gespeichert.'), reload: $tenant->wasChanged('name'));
    }

    public function updateBranding(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'brand_primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $old = ['brand_primary_color' => $tenant->brand_primary_color];
        $tenant->update($validated);

        $this->audit->log('tenant.branding_updated', $tenant, $old, $validated);

        return $this->saved($request, __('Markenfarbe gespeichert.'));
    }

    /**
     * Zählt eine spätere Buchung ohne Newsletter-Haken als Widerruf? Gilt für
     * den ganzen Betrieb, weil die Einwilligung am Gästeprofil hängt und das
     * Profil allen Standorten gehört. Wirkung:
     * GuestProfileService::recordBookingConsents(). Fehlt das Feld, bleibt
     * der gespeicherte Zustand; die Seite sendet das versteckte 0.
     */
    public function updateNewsletterConsent(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $request->validate([
            'newsletter_unticked_revokes' => ['sometimes', 'boolean'],
        ], [
            'newsletter_unticked_revokes.boolean' => __('Die Einstellung zum Newsletter-Haken kennt nur an oder aus. Bitte den Schalter neu setzen und speichern.'),
        ]);

        $old = ['newsletter_unticked_revokes' => (bool) $tenant->newsletter_unticked_revokes];
        $new = ['newsletter_unticked_revokes' => $request->has('newsletter_unticked_revokes')
            ? $request->boolean('newsletter_unticked_revokes')
            : $old['newsletter_unticked_revokes']];
        $tenant->update($new);

        $this->audit->log('tenant.newsletter_consent_updated', $tenant, $old, $new);

        return $this->saved($request, __('Newsletter-Einwilligung gespeichert.'));
    }

    public function updateTenantType(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:restaurant,salon'],
        ], [], ['type' => __('Betriebstyp')]);

        $old = $tenant->getRawOriginal('type');
        $tenant->update(['type' => TenantType::from($validated['type'])]);

        $this->audit->log('tenant.type_changed', $tenant, ['type' => $old], ['type' => $validated['type']]);

        // reload: Der Typwechsel ändert Navigation, Bezeichnungen und die
        // öffentliche Buchungsseite.
        return $this->saved($request, __('Betriebstyp geändert auf: :type', [
            'type' => TenantType::from($validated['type'])->label(),
        ]), reload: true);
    }
}

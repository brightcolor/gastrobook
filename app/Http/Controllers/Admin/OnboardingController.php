<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\BookingSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OnboardingController extends Controller
{
    use RespondsToSettingsForms;

    /** Felder des Schritts „Buchungsregeln“ aus BookingSettingRules::booking(); Salons haben keine Gruppengröße online. */
    private const RULE_FIELDS = ['min_lead_minutes', 'max_advance_days', 'min_party_online', 'max_party_online', 'booking_confirmation_mode', 'require_email_confirmation'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show()
    {
        $tenant = $this->context->tenant();
        $location = $this->context->location();

        abort_if($tenant === null || $location === null, 404);

        if ($tenant->onboarding_completed_at !== null) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.onboarding.index', [
            'tenant' => $tenant,
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'limits' => (array) config('swayy.booking'),
            'salon' => $tenant->isSalon(),
        ]);
    }

    /**
     * Schritt „Buchungsregeln“: schreibt Vorlauf, Vorausbuchung, Gruppengröße
     * online, Bestätigung und E-Mail-Bestätigung, bei Restaurants dazu die
     * Besuchsdauer. Alle übrigen Einstellungen des Standorts bleiben, wie sie
     * sind. Regeln und Grenzen sind die der Seiten „Buchungsregeln“ und
     * „Kapazität & Dauer“.
     */
    public function updateRules(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $salon = $this->context->tenant()->isSalon();
        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);

        $rules = Arr::only(BookingSettingRules::booking($salon), self::RULE_FIELDS);
        if (! $salon) {
            $rules += Arr::only(BookingSettingRules::capacity($settings, salon: false), ['default_duration_minutes']);
        }
        $validated = $request->validate($rules, BookingSettingRules::messages(), BookingSettingRules::attributes());

        $values = [
            'min_lead_minutes' => (int) $validated['min_lead_minutes'],
            'max_advance_days' => (int) $validated['max_advance_days'],
            'auto_confirm' => $validated['booking_confirmation_mode'] === 'auto',
            'request_only' => false,
            'require_email_confirmation' => $request->boolean('require_email_confirmation'),
        ];
        if (! $salon) {
            $values += [
                'min_party_online' => (int) $validated['min_party_online'],
                'max_party_online' => (int) $validated['max_party_online'],
                'default_duration_minutes' => (int) $validated['default_duration_minutes'],
            ];
        }

        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('onboarding.rules_updated', $settings, $old, $values);

        return $this->saved($request, __('Buchungsregeln gespeichert.'));
    }

    public function complete(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $tenant->update(['onboarding_completed_at' => now()]);

        return redirect()->route('admin.dashboard')
            ->with('success', __('Setup abgeschlossen! Dein Betrieb ist jetzt buchbar.'));
    }
}

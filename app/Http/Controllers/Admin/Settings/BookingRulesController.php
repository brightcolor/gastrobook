<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\BookingSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsregeln: Vorlauf, Vorausbuchung,
 * Gruppengröße online, Fristen für Gäste, Bestätigung, Warteliste und
 * Walk-ins. Salons stellen Vorlauf, Vorausbuchung, Fristen, Bestätigung und
 * Warteliste ein; Gruppengröße online und Walk-ins gehören zum Restaurant.
 * Gilt für den gewählten Standort.
 */
class BookingRulesController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return view('admin.settings.rules', [
            'settings' => $location->effectiveSettings(),
            'salon' => $this->context->tenant()->isSalon(),
            'limits' => (array) config('swayy.booking'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $salon = $this->context->tenant()->isSalon();

        $validated = $request->validate(
            BookingSettingRules::booking($salon),
            BookingSettingRules::messages(),
            BookingSettingRules::attributes(),
        );

        $values = [
            'min_lead_minutes' => (int) $validated['min_lead_minutes'],
            'max_advance_days' => (int) $validated['max_advance_days'],
            'cancellation_deadline_minutes' => (int) $validated['cancellation_deadline_minutes'],
            'modification_deadline_minutes' => (int) $validated['modification_deadline_minutes'],
            ...BookingSettingRules::confirmationColumns($validated['booking_confirmation_mode']),
            'require_email_confirmation' => $request->boolean('require_email_confirmation'),
            'waitlist_enabled' => $request->boolean('waitlist_enabled'),
        ];
        if (! $salon) {
            $values += [
                'min_party_online' => (int) $validated['min_party_online'],
                'max_party_online' => (int) $validated['max_party_online'],
                'large_group_email' => ($validated['large_group_email'] ?? null) ?: null,
                'walkins_enabled' => $request->boolean('walkins_enabled'),
            ];
        }

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.booking_rules_updated', $settings, $old, $values);

        return $this->saved($request, __('Buchungsregeln gespeichert.'));
    }
}

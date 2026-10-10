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
 * Einstellungen › Platz & Zeiten › Kapazität & Dauer: Takt der Uhrzeiten,
 * Dauer eines Besuchs, Puffer, Tischzeiten je Gruppengröße und wie Swayy die
 * Plätze zählt. Salons stellen Takt, Puffer und Lückenoptimierung ein; Dauer
 * und Plätze ergeben sich dort aus Leistungen und Personen. Gilt für den
 * gewählten Standort.
 */
class CapacityController extends Controller
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
        $settings = $location->effectiveSettings();

        return view('admin.settings.capacity', [
            'settings' => $settings,
            'salon' => $this->context->tenant()->isSalon(),
            'intervals' => BookingSettingRules::slotIntervals($settings),
            'limits' => (array) config('swayy.booking'),
            'durationRules' => (array) ($settings->duration_rules ?? []),
        ]);
    }

    /**
     * Die Tischzeiten ersetzen die gespeicherte Liste als Ganzes: Zeilen, die
     * das Formular nicht mehr sendet, sind entfernt. Neue Zeilen behalten ihre
     * Kennung (n1, n2 …) auch nach dem Speichern; ein zweites Speichern mit
     * denselben Kennungen ergibt dieselbe Liste.
     */
    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $salon = $this->context->tenant()->isSalon();
        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);

        $validated = $request->validate(
            BookingSettingRules::capacity($settings, $salon),
            BookingSettingRules::messages(),
            BookingSettingRules::attributes(),
        );

        $values = [
            'slot_interval_minutes' => (int) $validated['slot_interval_minutes'],
            'buffer_minutes' => (int) $validated['buffer_minutes'],
        ];
        if ($salon) {
            $values['gap_optimization_enabled'] = $request->boolean('gap_optimization_enabled');
        } else {
            $values += [
                'default_duration_minutes' => (int) $validated['default_duration_minutes'],
                'capacity_mode' => $validated['capacity_mode'],
                'max_covers_per_slot' => isset($validated['max_covers_per_slot']) ? (int) $validated['max_covers_per_slot'] : null,
                'duration_rules' => BookingSettingRules::durationRules((array) $request->input('duration_rules', [])),
            ];
        }

        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.capacity_updated', $settings, $old, $values);

        return $this->saved($request, __('Kapazität und Dauer gespeichert.'));
    }
}

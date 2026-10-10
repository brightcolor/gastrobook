<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsseite & Widget: Adresse der
 * Buchungsseite, Tischplan und Konfetti auf der Buchungsseite und der Code
 * für die eigene Website. Gilt für den gewählten Standort. Den Tischplan
 * zeigt nur die Buchungsseite des Restaurants (PublicBookingController::floorplan
 * antwortet für Salons mit 404); Salons stellen das Konfetti ein, das die
 * Bestätigungsseite für beide Betriebstypen zeigt.
 */
class BookingPageController extends Controller
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
        $tenant = $this->context->tenant();

        // Mit einem buchbaren Standort reicht die kurze Adresse des Betriebs;
        // bei mehreren wählt der Gast dort zuerst den Standort.
        $bookable = $tenant->locations()->where('is_active', true)->where('online_booking_enabled', true)->count();
        $short = $location->slug === $tenant->slug || $bookable <= 1;

        return view('admin.settings.booking-page', [
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'salon' => $tenant->isSalon(),
            'acceptsBookings' => $location->is_active && $location->online_booking_enabled,
            'severalLocations' => $bookable > 1,
            'landingUrl' => route('booking.landing', $tenant->slug),
            'bookingUrl' => $bookable <= 1
                ? route('booking.landing', $tenant->slug)
                : route('booking.show', [$tenant->slug, $location->slug]),
            'embedSrc' => $short
                ? route('booking.embed.single', $tenant->slug)
                : route('booking.embed', [$tenant->slug, $location->slug]),
            'popupSrc' => $short
                ? route('booking.widget.popup.single', $tenant->slug)
                : route('booking.widget.popup', [$tenant->slug, $location->slug]),
            'brandColor' => $this->brandColor($tenant),
            'defaultLabel' => $tenant->isSalon() ? __('Termin buchen') : __('Jetzt reservieren'),
            'labelMax' => (int) config('swayy.ui.widget_label_max'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $rules = ['confetti_on_booking' => ['required', 'boolean']];
        if (! $this->context->tenant()->isSalon()) {
            $rules['public_floorplan_enabled'] = ['required', 'boolean'];
        }
        $request->validate($rules, $this->messages(array_keys($rules)), [
            'confetti_on_booking' => __('Konfetti nach dem Buchen'),
            'public_floorplan_enabled' => __('Tischplan zeigen'),
        ]);

        $values = [];
        foreach (array_keys($rules) as $field) {
            $values[$field] = $request->boolean($field);
        }

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.booking_page_updated', $settings, $old, $values);

        return $this->saved($request, __('Buchungsseite gespeichert.'));
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Einstellung und den nächsten
     * Schritt. Ein Schalter fehlt nur, wenn die Seite älter ist als der
     * Betriebstyp oder jemand das Formular von Hand sendet.
     *
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function messages(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field) {
            $messages["{$field}.required"] = __('Die Einstellung „:attribute“ fehlt im gesendeten Formular. Bitte die Seite neu laden und erneut speichern.');
            $messages["{$field}.boolean"] = __('Bitte „:attribute“ ein- oder ausschalten.');
        }

        return $messages;
    }

    /**
     * Markenfarbe des Betriebs für die Farbwahl und als Vorgabe im Code. Nur
     * die Schreibweise #rrggbb gilt; alles andere ersetzt die Vorgabe aus den
     * Einstellungen. Die Farbe steht auf Websites fremder Betreiber im Code.
     */
    private function brandColor(Tenant $tenant): string
    {
        $color = (string) $tenant->brand_primary_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/D', $color) === 1
            ? $color
            : (string) config('swayy.ui.default_brand_color');
    }
}

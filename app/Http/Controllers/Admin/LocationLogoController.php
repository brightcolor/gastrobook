<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Support\FileSize;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Logo je Standort (Einstellungen › Betrieb › Standorte, Seitenleiste des
 * Standorts). Formate, Größe und Ordner stehen in config/swayy.php
 * (uploads.*); SVG ist nie dabei, weil es Skripte tragen kann.
 */
class LocationLogoController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Vorschau in der Seitenleiste des Standorts. Die öffentliche Route
     * (brand.location.logo) liefert nur für aktive Standorte mit
     * Online-Buchung; hier gilt jeder Standort des Betriebs.
     */
    public function show(Location $location): mixed
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);
        $path = $location->brand_logo_path;
        abort_if($path === null || $path === '' || ! Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    public function store(Request $request, Location $location): mixed
    {
        // Die Route-Bindung filtert schon nach Betrieb; das hier fängt einen fehlenden Kontext ab.
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $maxKb = (int) config('swayy.uploads.logo_max_kb');
        $types = array_map('strval', (array) config('swayy.uploads.logo_types'));
        $wrongType = __('Die Datei ist kein Bild in einem erlaubten Format. Bitte das Logo als :types hochladen.', [
            'types' => self::typeList($types),
        ]);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:'.implode(',', $types), 'max:'.$maxKb],
        ], [
            'logo.required' => __('Bitte eine Bilddatei für das Logo auswählen.'),
            'logo.uploaded' => __('Das Logo kam nicht vollständig an. Bitte eine kleinere Datei wählen oder es erneut versuchen.'),
            'logo.image' => $wrongType,
            'logo.mimes' => $wrongType,
            'logo.max' => __('Das Logo ist größer als :size. Bitte eine kleinere Datei wählen.', ['size' => FileSize::fromKilobytes($maxKb)]),
        ]);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
        }

        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
        $location->update(['brand_logo_path' => $path]);
        $this->audit->log('location.logo.updated', $location);

        return $this->saved($request, __('Logo für „:name“ gespeichert.', ['name' => $location->name]), reload: true);
    }

    public function destroy(Request $request, Location $location): mixed
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
            $location->update(['brand_logo_path' => null]);
        }
        $this->audit->log('location.logo.deleted', $location);

        return $this->saved($request, __('Logo von „:name“ entfernt.', ['name' => $location->name]), reload: true);
    }

    /**
     * Endungen für Menschen: „JPG, PNG oder WEBP“.
     *
     * @param  list<string>  $types
     */
    public static function typeList(array $types): string
    {
        $upper = array_map('strtoupper', $types);
        $last = array_pop($upper);

        return $upper === [] ? (string) $last : implode(', ', $upper).' oder '.$last;
    }
}

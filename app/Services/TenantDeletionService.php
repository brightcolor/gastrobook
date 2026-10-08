<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Location;
use App\Models\ReservationAttachment;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Loescht einen Betrieb vollstaendig: erst die Dateien, dann die Datenbank.
 *
 * Ein Weg fuer „Betrieb loeschen“ in der Verwaltung und fuer DELETE aus dem
 * Kundenpanel. Die ON-DELETE-CASCADE raeumt nur die Datenbank; ohne den
 * ersten Schritt laegen Anhaenge mit Gastnamen und Unterschriften weiter auf
 * der Platte, ohne jede Zeile, ueber die man sie noch faende.
 */
final class TenantDeletionService
{
    public function delete(Tenant $tenant): void
    {
        $this->deleteFiles($tenant);

        // forceDelete umgeht SoftDeletes und loest die Fremdschluessel mit
        // ON DELETE CASCADE aus: Standorte, Reservierungen, Gaeste, Personal,
        // Einstellungen und Protokoll gehen mit, der Slug ist wieder frei.
        $tenant->forceDelete();
    }

    /**
     * Reservierungs-Anhaenge (privat), Eventbilder, Raumhintergruende und die
     * Logos von Betrieb und Standorten.
     */
    private function deleteFiles(Tenant $tenant): void
    {
        ReservationAttachment::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->each(fn (ReservationAttachment $a) => Storage::disk($a->disk)->delete($a->path));
        Storage::disk('local')->deleteDirectory('reservation-attachments/'.$tenant->id);

        $locationIds = Location::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id');

        Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('image_path')
            ->each(fn (Event $e) => Storage::disk('public')->delete($e->image_path));
        Room::withoutGlobalScopes()->whereIn('location_id', $locationIds)->whereNotNull('background_path')
            ->each(fn (Room $r) => Storage::disk('public')->delete($r->background_path));
        Location::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('brand_logo_path')
            ->each(fn (Location $l) => Storage::disk('public')->delete($l->brand_logo_path));
        if ($tenant->brand_logo_path !== null) {
            Storage::disk('public')->delete($tenant->brand_logo_path);
        }
    }
}

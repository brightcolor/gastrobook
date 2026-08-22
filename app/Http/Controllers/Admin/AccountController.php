<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Location;
use App\Models\ReservationAttachment;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        $isOwner = $tenant !== null
            && $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists();

        $isLastOwner = $isOwner
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1;

        return view('admin.account.index', compact('user', 'tenant', 'isOwner', 'isLastOwner'));
    }

    /**
     * Download the complete account as a JSON archive so the business can move
     * to another installation (or keep an offline copy). Owner-only: this file
     * contains every guest and reservation of the tenant.
     */
    public function export(Request $request, AccountExportService $exporter): StreamedResponse
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        abort_if($tenant === null, 404);
        abort_unless(
            $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists(),
            403
        );

        $data = $exporter->export($tenant);

        $this->audit->log('account.exported', $tenant, null, [
            'reservations' => count($data['reservations']),
            'guests' => count($data['guests']),
        ], null, $user, $tenant->id);

        $filename = 'swayy-export-'.Str::slug($tenant->name).'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Import a previously exported account file into the current business.
     * Additive: nothing existing is deleted. Runs in one transaction, so a
     * broken file leaves the account untouched.
     */
    public function import(Request $request, AccountImportService $importer)
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        abort_if($tenant === null, 404);
        abort_unless(
            $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists(),
            403
        );

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:51200'],
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.accepted' => 'Bitte bestätige, dass die Daten zum aktuellen Betrieb hinzugefügt werden.',
            'file.max' => 'Die Datei ist zu groß (max. 50 MB).',
        ]);

        $decoded = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($decoded)) {
            return back()->withErrors(['file' => 'Die Datei konnte nicht gelesen werden – ist es eine gültige Export-Datei?']);
        }

        try {
            $imported = $importer->import($tenant, $decoded);
        } catch (\RuntimeException $e) {
            // RuntimeException wirft der Importer selbst - das sind
            // verstaendliche Saetze fuer den Betrieb.
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Alles andere ist eine Datenbank- oder Programmiermeldung. Sie
            // enthaelt die fehlgeschlagene Anweisung samt der eingesetzten
            // Werte - also Gastdaten aus der Datei - und gehoert nicht ins
            // Formular. Ins Log gehoert sie sehr wohl.
            report($e);

            return back()->withErrors(['file' => 'Der Import ist fehlgeschlagen. Die Datei wurde nicht übernommen; im Serverprotokoll stehen die Einzelheiten.']);
        }

        $this->audit->log('account.imported', $tenant, null, $imported, null, $user, $tenant->id);

        // Admin UI is German-only, so name the sections in German regardless of APP_LOCALE.
        $summary = collect($imported)
            ->map(fn ($count, $section) => $count.' '.__('import.'.$section, [], 'de'))
            ->implode(', ');

        return back()->with('success', __('Import abgeschlossen: :summary.', ['summary' => $summary]));
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'confirm' => ['required', 'in:LÖSCHEN'],
        ], [
            'confirm.in' => 'Bitte tippe genau „LÖSCHEN" ein, um dein Konto zu löschen.',
        ]);

        $user = $request->user();

        // Ueber ALLE Betriebe pruefen, nicht nur ueber den gerade aktiven. Wer
        // in Betrieb A nur mitarbeitet und in Betrieb B alleiniger Inhaber ist,
        // kam vorher durch: Das Loeschen des Kontos raeumte ueber die Kaskade
        // auch die Inhaberschaft in B ab, und B stand ohne Inhaber da -
        // niemand konnte dort noch exportieren, importieren oder den Betrieb
        // aufloesen.
        $verwaist = Tenant::whereIn('id', $user->tenants()->wherePivot('role', 'tenant_owner')->pluck('tenants.id'))
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1);

        if ($verwaist->isNotEmpty()) {
            return back()->withErrors(['confirm' => __(
                'Du bist einziger Inhaber von :betriebe. Lösche diese Betriebe zuerst oder übertrage die Inhaberrolle.',
                ['betriebe' => $verwaist->pluck('name')->implode(', ')]
            )]);
        }

        $this->audit->log('user.self_deleted', $user, ['email' => $user->email, 'name' => $user->name]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        return redirect('/')->with('success', __('Dein Konto wurde gelöscht. Auf Wiedersehen!'));
    }

    public function destroyTenant(Request $request)
    {
        $request->validate([
            'confirm' => ['required'],
        ]);

        $tenant = $this->context->tenant();
        $user = $request->user();

        abort_if($tenant === null, 404);

        // Only the tenant owner may delete the business
        abort_unless(
            $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists(),
            403
        );

        if ($request->input('confirm') !== $tenant->name) {
            return back()->withErrors(['confirm_tenant' => 'Der Name stimmt nicht überein.']);
        }

        $this->audit->log('tenant.deleted', $tenant, [
            'name' => $tenant->name,
            'deleted_by' => $user->email,
        ]);

        // Dateien zuerst: Die ON-DELETE-CASCADE raeumt nur die Datenbank. Ohne
        // diesen Schritt liegen die Anhaenge mit Gastnamen und Unterschriften
        // weiter auf der Platte – ohne jede Zeile, ueber die man sie noch finden
        // koennte. Das waere weder geloescht noch auffindbar.
        $this->deleteTenantFiles($tenant);

        // Hard delete: forceDelete bypasses SoftDeletes and triggers the
        // ON DELETE CASCADE foreign keys, so everything tied to the tenant
        // (locations, reservations, guests, staff, settings, audit logs, …)
        // is permanently removed and the slug is freed again.
        $tenant->forceDelete();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', __('Der Betrieb wurde vollständig gelöscht.'));
    }

    /**
     * Alle hochgeladenen Dateien eines Betriebs von der Platte nehmen:
     * Reservierungs-Anhaenge (privat), Eventbilder, Raumhintergruende und Logos.
     */
    private function deleteTenantFiles(Tenant $tenant): void
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
    }
}

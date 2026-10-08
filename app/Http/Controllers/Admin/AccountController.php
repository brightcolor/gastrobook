<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccountImportException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use App\Services\AuditLogger;
use App\Services\TenantDeletionService;
use App\Support\FileSize;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly TenantDeletionService $deletion,
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

        $maxKb = (int) config('swayy.uploads.import_max_kb');
        $request->validate([
            // Die Grenze steht in config/swayy.php (uploads.import_max_kb) und
            // liegt höchstens bei 20 MB, weil PHP und beide nginx genau so viel
            // durchlassen (docker/php.ini).
            'file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:'.$maxKb],
            'confirm' => ['required', 'accepted'],
        ], [
            'file.required' => __('Bitte die Export-Datei auswählen.'),
            'file.mimetypes' => __('Die Datei ist keine Export-Datei von Swayy. Bitte die JSON-Datei aus „Daten herunterladen“ wählen.'),
            'file.max' => __('Die Datei ist größer als :size, mehr nimmt der Import nicht an. Bitte den Support von Swayy fragen.', [
                'size' => FileSize::fromKilobytes($maxKb),
            ]),
            'confirm.required' => __('Bitte bestätige, dass die Daten zum aktuellen Betrieb hinzugefügt werden.'),
            'confirm.accepted' => __('Bitte bestätige, dass die Daten zum aktuellen Betrieb hinzugefügt werden.'),
        ]);

        $decoded = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($decoded)) {
            return back()->withErrors(['file' => 'Die Datei konnte nicht gelesen werden – ist es eine gültige Export-Datei?']);
        }

        try {
            $imported = $importer->import($tenant, $decoded);
        } catch (AccountImportException $e) {
            // Nur die eigene Klasse. `RuntimeException` als Fangnetz reichte
            // nicht: `QueryException` erbt ueber `PDOException` ebenfalls
            // davon, und deren Meldung traegt die fehlgeschlagene Anweisung
            // samt eingesetzter Werte - also Gastdaten aus der Datei.
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Alles andere ist eine Datenbank- oder Programmiermeldung. Sie
            // enthaelt die fehlgeschlagene Anweisung samt der eingesetzten
            // Werte - also Gastdaten aus der Datei - und gehoert nicht ins
            // Formular. Ins Log gehoert sie sehr wohl.
            report($e);

            return back()->withErrors(['file' => __('Der Import ist fehlgeschlagen, die Datei wurde nicht übernommen. Bitte den Support von Swayy fragen und Datum und Uhrzeit des Versuchs nennen; dort stehen die Einzelheiten im Serverprotokoll.')]);
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
            'confirm.required' => __('Bitte tippe zur Bestätigung „LÖSCHEN“ ein.'),
            'confirm.in' => __('Bitte tippe genau „LÖSCHEN“ ein, in Großbuchstaben, um dein Konto zu löschen.'),
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
            return back()->withErrors(['confirm_tenant' => __('Der eingetippte Name weicht von „:name“ ab. Bitte genau so eintippen, mit Groß- und Kleinschreibung.', ['name' => $tenant->name])]);
        }

        $this->audit->log('tenant.deleted', $tenant, [
            'name' => $tenant->name,
            'deleted_by' => $user->email,
        ]);

        // Erst die Dateien, dann der Betrieb samt allem, was an ihm haengt.
        $this->deletion->delete($tenant);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', __('Der Betrieb wurde vollständig gelöscht.'));
    }
}

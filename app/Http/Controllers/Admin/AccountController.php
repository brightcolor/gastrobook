<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AuditLogger;
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

    public function destroy(Request $request)
    {
        $request->validate([
            'confirm' => ['required', 'in:LÖSCHEN'],
        ], [
            'confirm.in' => 'Bitte tippe genau „LÖSCHEN" ein, um dein Konto zu löschen.',
        ]);

        $user = $request->user();
        $tenant = $this->context->tenant();

        if ($tenant !== null
            && $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists()
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return back()->withErrors(['confirm' => __('Du bist der einzige Inhaber dieses Betriebs. Lösche den Betrieb zuerst oder übertrage die Inhaberrolle.')]);
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
}

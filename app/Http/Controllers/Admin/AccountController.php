<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $isLastOwner = $tenant !== null
            && $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'tenant_owner')->exists()
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1;

        return view('admin.account.index', compact('user', 'tenant', 'isLastOwner'));
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

        // Cascade deletes everything (locations, reservations, guests, staff, …)
        $tenant->delete();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', __('Der Betrieb wurde vollständig gelöscht.'));
    }
}

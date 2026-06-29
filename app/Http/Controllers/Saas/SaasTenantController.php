<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SaasTenantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function dashboard(Request $request)
    {
        $this->authorizeSaas($request);

        $byStatus = Tenant::query()
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $reservationsThisMonth = Reservation::withoutGlobalScope('tenant')
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->count();

        return view('saas.dashboard', [
            'tenantsTotal' => Tenant::count(),
            'byStatus' => $byStatus,
            'trialing' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->count(),
            'usersTotal' => User::count(),
            'saasAdmins' => User::whereNotNull('saas_role')->count(),
            'reservationsThisMonth' => $reservationsThisMonth,
            'recentTenants' => Tenant::with('plan')->latest()->limit(8)->get(),
            'plans' => Plan::withCount('tenants')->orderBy('sort_order')->get(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeSaas($request);

        $tenants = Tenant::query()
            ->with('plan')
            ->withCount('locations', 'memberships')
            ->when($request->input('q'), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->paginate(50);

        $reservationCounts = Reservation::withoutGlobalScope('tenant')
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->selectRaw('tenant_id, count(*) as cnt')
            ->groupBy('tenant_id')
            ->pluck('cnt', 'tenant_id');

        return view('saas.tenants.index', [
            'tenants' => $tenants,
            'plans' => Plan::orderBy('sort_order')->get(),
            'reservationCounts' => $reservationCounts,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeSaas($request, write: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'plan_id' => ['required', 'exists:plans,id'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc'],
            'location_name' => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $plan = Plan::findOrFail($validated['plan_id']);

            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'plan_id' => $plan->id,
                'status' => 'active',
                'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
            ]);

            $password = Str::random(16);
            $owner = User::firstOrCreate(
                ['email' => strtolower($validated['owner_email'])],
                ['name' => $validated['owner_name'], 'password' => Hash::make($password)]
            );

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => 'tenant_owner',
                'all_locations' => true,
            ]);

            $location = Location::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['location_name'],
                'slug' => Str::slug($validated['location_name']),
                'timezone' => $validated['timezone'] ?? 'Europe/Berlin',
            ]);
            $location->settings()->create(['tenant_id' => $tenant->id]);

            return [$tenant, $owner, $password];
        });

        [$tenant, $owner, $password] = $result;

        $this->audit->log('tenant.created', $tenant, null, ['name' => $tenant->name], null, $request->user(), $tenant->id);

        return back()->with('success', __('Mandant ":name" angelegt. Inhaber: :email / Initialpasswort: :pw (nur einmal sichtbar!)', [
            'name' => $tenant->name, 'email' => $owner->email, 'pw' => $password,
        ]));
    }

    public function updateStatus(Request $request, Tenant $tenant)
    {
        $this->authorizeSaas($request, write: true);

        $validated = $request->validate(['status' => ['required', 'in:active,suspended,cancelled']]);
        $old = $tenant->status;
        $tenant->update(['status' => $validated['status']]);

        $this->audit->log('tenant.status_changed', $tenant, ['status' => $old], $validated, null, $request->user(), $tenant->id);

        return back()->with('success', __('Status geändert.'));
    }

    public function extendTrial(Request $request, Tenant $tenant)
    {
        $this->authorizeSaas($request, write: true);

        $validated = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);

        // Extend from the later of "now" or the current trial end, and reactivate
        // the tenant so an expired account works again immediately.
        $base = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()
            ? $tenant->trial_ends_at
            : now();
        $newEnd = $base->copy()->addDays((int) $validated['days']);

        $old = ['status' => $tenant->status, 'trial_ends_at' => $tenant->trial_ends_at?->toDateTimeString()];
        $tenant->update(['trial_ends_at' => $newEnd, 'status' => 'active']);

        $this->audit->log('tenant.trial_extended', $tenant, $old,
            ['trial_ends_at' => $newEnd->toDateTimeString(), 'days' => $validated['days']],
            null, $request->user(), $tenant->id);

        return back()->with('success', __('Trial verlängert bis :date.', [
            'date' => $newEnd->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'),
        ]));
    }

    public function updatePlan(Request $request, Tenant $tenant)
    {
        $this->authorizeSaas($request, write: true);

        $validated = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);
        $old = $tenant->plan_id;
        $tenant->update(['plan_id' => $validated['plan_id']]);

        $this->audit->log('tenant.plan_changed', $tenant, ['plan_id' => $old], $validated, null, $request->user(), $tenant->id);

        return back()->with('success', __('Tarif geändert.'));
    }

    /**
     * Support access: enter a tenant as SaaS admin — always audited.
     */
    public function impersonate(Request $request, Tenant $tenant)
    {
        $this->authorizeSaas($request);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $request->user()->forceFill([
            'current_tenant_id' => $tenant->id,
            'current_location_id' => null,
        ])->save();

        session(['impersonating_tenant_id' => $tenant->id]);

        $this->audit->log('support.impersonation_started', $tenant, null, null, [
            'reason' => $validated['reason'] ?? null,
        ], $request->user(), $tenant->id);

        return redirect()->route('admin.dashboard')
            ->with('success', __('Supportzugriff auf ":name" gestartet.', ['name' => $tenant->name]));
    }

    public function stopImpersonation(Request $request)
    {
        $tenantId = session('impersonating_tenant_id');
        if ($tenantId) {
            $this->audit->log('support.impersonation_ended', null, null, null, null, $request->user(), $tenantId);
        }

        session()->forget('impersonating_tenant_id');
        $request->user()->forceFill(['current_tenant_id' => null, 'current_location_id' => null])->save();

        return redirect()->route('saas.tenants.index');
    }

    private function authorizeSaas(Request $request, bool $write = false): void
    {
        $user = $request->user();
        abort_unless($user?->isSaasAdmin(), 403);
        if ($write) {
            abort_unless(in_array($user->saas_role, ['super_admin', 'support_admin'], true), 403);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}

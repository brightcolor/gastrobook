<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $logs = AuditLog::query()
            ->where('tenant_id', $tenant->id) // explicit: AuditLog has no global scope
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('until'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(100)
            ->withQueryString();

        // Timestamps are stored in UTC; display them in the location's local
        // timezone (default Europe/Berlin) so the times match the wall clock.
        $tz = $this->context->location()?->timezone ?? config('app.display_timezone', 'Europe/Berlin');

        return view('admin.audit.index', ['logs' => $logs, 'tz' => $tz]);
    }
}

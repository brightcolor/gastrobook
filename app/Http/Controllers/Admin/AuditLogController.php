<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Einstellungen › Betrieb › Protokoll: wer wann was geändert hat. Filter nach
 * Bereich (Anfang des Aktionsnamens, AuditLog::SUBJECTS), Zeitraum und einem
 * einzelnen Eintrag (entity + entity_id, Kürzel aus config/swayy.php,
 * audit.entities).
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $area = (string) $request->query('area', '');
        $area = array_key_exists($area, AuditLog::SUBJECTS) ? $area : '';
        $entity = (string) $request->query('entity', '');
        $entityClass = ((array) config('swayy.audit.entities'))[$entity] ?? null;
        $entityId = (int) $request->query('entity_id', 0);
        $byEntity = $entityClass !== null && $entityId > 0;

        $logs = AuditLog::query()
            ->where('tenant_id', $tenant->id) // explicit: AuditLog has no global scope
            ->when($area !== '', fn ($q) => $q->where('action', 'like', $area.'.%'))
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($byEntity, fn ($q) => $q->where('entity_type', $entityClass)->where('entity_id', $entityId))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('until'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate((int) config('swayy.audit.per_page'))
            ->withQueryString();

        // Timestamps are stored in UTC; display them in the location's local
        // timezone so the times match the wall clock.
        $tz = $this->context->location()?->timezone ?? config('swayy.locations.default_timezone');

        return view('admin.audit.index', [
            'logs' => $logs,
            'tz' => $tz,
            'areas' => collect(AuditLog::SUBJECTS)->sortBy(fn (string $label) => Str::ascii($label))->all(),
            'area' => $area,
            'entityLabel' => $byEntity ? (AuditLog::SUBJECTS[$entity] ?? $entity).' Nr. '.$entityId : null,
            'filtered' => $area !== '' || $byEntity || $request->filled('action') || $request->filled('from') || $request->filled('until'),
        ]);
    }
}

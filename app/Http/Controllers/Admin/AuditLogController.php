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

        // Nur Text aus der Adresse; Listen (?area[]=…) und andere Formen gelten als leer.
        $text = function (string $key) use ($request): string {
            $value = $request->query($key);

            return is_string($value) ? trim($value) : '';
        };
        $date = fn (string $key): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text($key)) === 1 ? $text($key) : '';

        $area = array_key_exists($text('area'), AuditLog::SUBJECTS) ? $text('area') : '';
        $entity = $text('entity');
        $entityClass = ((array) config('swayy.audit.entities'))[$entity] ?? null;
        $entityId = ctype_digit($text('entity_id')) ? (int) $text('entity_id') : 0;
        $byEntity = $entityClass !== null && $entityId > 0;
        $filters = [
            'action' => $text('action'),
            'user_id' => ctype_digit($text('user_id')) ? $text('user_id') : '',
            'from' => $date('from'),
            'until' => $date('until'),
            'entity' => $byEntity ? $entity : '',
            'entity_id' => $byEntity ? (string) $entityId : '',
        ];

        $logs = AuditLog::query()
            ->where('tenant_id', $tenant->id) // explicit: AuditLog has no global scope
            ->when($area !== '', fn ($q) => $q->where('action', 'like', $area.'.%'))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', 'like', $filters['action'].'%'))
            ->when($byEntity, fn ($q) => $q->where('entity_type', $entityClass)->where('entity_id', $entityId))
            ->when($filters['user_id'] !== '', fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when($filters['from'] !== '', fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['until'] !== '', fn ($q) => $q->where('created_at', '<=', $filters['until'].' 23:59:59'))
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
            'areas' => collect(AuditLog::SUBJECTS)->sortBy(fn (string $label) => Str::lower(Str::ascii($label)))->all(),
            'area' => $area,
            'filters' => $filters,
            'entityLabel' => $byEntity ? (AuditLog::SUBJECTS[$entity] ?? $entity).' Nr. '.$entityId : null,
            'filtered' => $area !== '' || $byEntity || $filters['action'] !== '' || $filters['from'] !== '' || $filters['until'] !== '',
        ]);
    }
}

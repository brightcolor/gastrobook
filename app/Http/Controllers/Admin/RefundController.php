<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\RefundService;
use App\Support\TenantContext;

class RefundController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RefundService $refunds,
    ) {}

    public function index()
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $refunds = Refund::where('tenant_id', $tenant->id)
            ->with('reservation:id,code,guest_name_snapshot')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.refunds.index', [
            'refunds' => $refunds,
            'pendingCount' => Refund::where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
        ]);
    }

    public function approve(Refund $refund)
    {
        abort_if($refund->tenant_id !== $this->context->tenantId(), 404);
        $this->refunds->approve($refund, request()->user());

        return back()->with('success', __('Rückerstattung freigegeben.'));
    }

    public function reject(Refund $refund)
    {
        abort_if($refund->tenant_id !== $this->context->tenantId(), 404);
        $this->refunds->reject($refund, request()->user());

        return back()->with('success', __('Rückerstattung abgelehnt.'));
    }

    public function retry(Refund $refund)
    {
        abort_if($refund->tenant_id !== $this->context->tenantId(), 404);

        // Bedingtes Update statt Lesen-Pruefen-Schreiben: Der gelesene Status
        // war bis zum Schreiben laengst veraltet. Drueckten zwei Leute den
        // Knopf, setzte der zweite das 'processing' des ersten - der gerade
        // beim Anbieter unterwegs war - wieder auf 'approved' zurueck und
        // loeste damit eine zweite echte Erstattung aus.
        if (! $this->refunds->reopen($refund)) {
            // Ein alter Fehlversuch ist etwas anderes als ein gerade laufender.
            // "Seite neu laden" hilft dort nicht - und der Knopf darf ihn auch
            // nicht mehr auslösen, weil der Wiederholungsschutz des Anbieters
            // nach 24 Stunden abgelaufen ist.
            $zuAlt = $refund->status === 'failed'
                && $refund->updated_at !== null
                && $refund->updated_at->lt(now()->subHours(RefundService::RETRY_WINDOW_HOURS));

            return back()->withErrors(['status' => $zuAlt
                ? __('Dieser Fehlversuch liegt zu lange zurück. Bitte beim Zahlungsanbieter nachsehen, ob die Rückerstattung doch gelaufen ist, und sie nötigenfalls dort auslösen.')
                : __('Diese Rückerstattung lässt sich gerade nicht erneut versuchen. Bitte die Seite neu laden.')]);
        }

        $this->refunds->process($refund->refresh());

        return back()->with('success', __('Rückerstattung erneut versucht.'));
    }
}

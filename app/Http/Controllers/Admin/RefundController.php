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
        abort_unless($refund->status === 'failed', 422);
        $refund->update(['status' => 'approved', 'error' => null]);
        $this->refunds->process($refund);

        return back()->with('success', __('Rückerstattung erneut versucht.'));
    }
}

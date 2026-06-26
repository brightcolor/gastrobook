<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show()
    {
        $tenant = $this->context->tenant();
        $location = $this->context->location();

        abort_if($tenant === null || $location === null, 404);

        if ($tenant->onboarding_completed_at !== null) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.onboarding.index', compact('tenant', 'location'));
    }

    public function complete(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $tenant->update(['onboarding_completed_at' => now()]);

        return redirect()->route('admin.dashboard')
            ->with('success', __('Setup abgeschlossen! Dein Betrieb ist jetzt buchbar.'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class GuestApiController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->tokenCan('guests:read'), 403);

        $guests = Guest::query()
            ->where('anonymized', false)
            ->when($request->input('q'), fn ($q, $term) => $q->search($term))
            ->orderBy('last_name')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json([
            'data' => $guests->getCollection()->map(fn (Guest $g) => [
                'id' => $g->id,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'email' => $g->email,
                'phone' => $g->phone,
                'visit_count' => $g->visit_count,
                'no_show_count' => $g->no_show_count,
                'is_vip' => $g->is_vip,
                'marketing_consent' => $g->marketing_consent,
            ]),
            'meta' => ['total' => $guests->total(), 'current_page' => $guests->currentPage()],
        ]);
    }

    public function show(Request $request, Guest $guest)
    {
        abort_unless($request->user()->tokenCan('guests:read'), 403);

        // Das Route-Model-Binding laeuft VOR ResolveApiTenant (SubstituteBindings
        // kommt aus der api-Gruppe, unsere Middleware haengt an der Route). Zum
        // Bindungszeitpunkt ist der Mandantenkontext leer, der globale Scope
        // filtert also nichts – die Pruefung muss hier explizit stehen.
        abort_if($guest->tenant_id !== $this->context->tenantId(), 404);

        return response()->json(['data' => [
            'id' => $guest->id,
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'preferences' => $guest->preferences,
            'allergies' => $guest->allergies,
            'visit_count' => $guest->visit_count,
            'no_show_count' => $guest->no_show_count,
            'last_visit_at' => $guest->last_visit_at?->toIso8601String(),
            'is_vip' => $guest->is_vip,
        ]]);
    }
}

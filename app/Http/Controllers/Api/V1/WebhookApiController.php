<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookApiController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->tokenCan('webhooks:manage'), 403);

        return response()->json(['data' => WebhookEndpoint::all()->map(fn ($e) => [
            'id' => $e->id, 'url' => $e->url, 'events' => $e->events,
            'is_active' => $e->is_active, 'failure_count' => $e->failure_count,
        ])]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->tokenCan('webhooks:manage'), 403);

        $validated = $request->validate([
            'url' => ['required', 'url:https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:64'],
        ]);

        $secret = Str::random(40);
        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => $validated['events'],
        ]);

        $this->audit->log('webhook.created', $endpoint, null, ['url' => $endpoint->url]);

        return response()->json(['data' => [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret' => $secret, // shown exactly once
        ]], 201);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        abort_unless($request->user()->tokenCan('webhooks:manage'), 403);

        $this->audit->log('webhook.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();

        return response()->json([], 204);
    }
}

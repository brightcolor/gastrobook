<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $tags = Tag::where('tenant_id', $this->context->tenantId())
            ->whereIn('scope', ['reservation', 'both'])
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $tag = Tag::firstOrCreate(
            ['tenant_id' => $this->context->tenantId(), 'name' => $validated['name'], 'scope' => 'reservation'],
            ['color' => $validated['color']]
        );

        if (! $tag->wasRecentlyCreated) {
            $tag->update(['color' => $validated['color']]);
        }

        $this->audit->log('tag.created', $tag);

        return response()->json(['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color], 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $tag->update(['name' => $validated['name'], 'color' => $validated['color']]);
        $this->audit->log('tag.updated', $tag);

        return response()->json(['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403);
        $this->audit->log('tag.deleted', $tag, ['name' => $tag->name, 'color' => $tag->color]);
        $tag->delete();

        return response()->json(['ok' => true]);
    }

    public function syncReservation(Request $request, Reservation $reservation): JsonResponse
    {
        abort_if($reservation->tenant_id !== $this->context->tenantId(), 404);

        $validated = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $allowed = Tag::where('tenant_id', $this->context->tenantId())
            ->whereIn('id', $validated['tag_ids'])
            ->pluck('id');

        $reservation->tags()->sync($allowed);
        $this->audit->log('reservation.tags_updated', $reservation, null, ['tag_ids' => $allowed->toArray()]);

        return response()->json(['ok' => true, 'tag_ids' => $allowed->values()]);
    }
}

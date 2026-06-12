<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Services\GuestPrivacyService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuestPrivacyService $privacy,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $query = Guest::query()->withCount('reservations');

        if ($search = $request->input('q')) {
            $query->search($search);
        }
        if ($request->boolean('vip')) {
            $query->where('is_vip', true);
        }
        if ($tagId = $request->input('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        $guests = $query->orderBy('last_name')->paginate(50)->withQueryString();

        return view('admin.guests.index', [
            'guests' => $guests,
            'tags' => Tag::where('scope', '!=', 'reservation')->orderBy('name')->get(),
        ]);
    }

    /** JSON suggestions for reservation quick entry. */
    public function suggest(Request $request)
    {
        $term = (string) $request->input('q', '');
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Guest::query()->search($term)->where('anonymized', false)->limit(8)->get()
                ->map(fn (Guest $g) => [
                    'id' => $g->id,
                    'name' => $g->fullName(),
                    'email' => $g->email,
                    'phone' => $g->phone,
                    'visits' => $g->visit_count,
                    'vip' => $g->is_vip,
                    'allergies' => $g->allergies,
                ])
        );
    }

    public function show(Request $request, Guest $guest)
    {
        $guest->load(['tags', 'consents']);

        $canSeeSensitive = $request->user()->canInTenant('guest_notes.sensitive.view', $this->context->tenant());
        $notes = $guest->notes()->when(! $canSeeSensitive, fn ($q) => $q->where('is_sensitive', false))->with('user')->latest()->get();

        $reservations = $guest->reservations()->orderByDesc('start_at')->limit(50)->get();

        return view('admin.guests.show', [
            'guest' => $guest,
            'notes' => $notes,
            'reservations' => $reservations,
            'upcoming' => $reservations->filter(fn ($r) => $r->start_at->isFuture() && $r->status->isActive()),
            'noShows' => $reservations->filter(fn ($r) => $r->status->value === 'no_show'),
        ]);
    }

    public function update(Request $request, Guest $guest)
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'birthday' => ['nullable', 'date'],
            'preferences' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'accessibility_notes' => ['nullable', 'string', 'max:500'],
            'is_vip' => ['nullable', 'boolean'],
        ]);

        $old = $guest->only(array_keys($validated));
        $guest->update($validated + ['is_vip' => $request->boolean('is_vip')]);

        $this->audit->log('guest.updated', $guest, $old, $validated);

        return back()->with('success', __('Gastprofil gespeichert.'));
    }

    public function addNote(Request $request, Guest $guest)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'is_sensitive' => ['nullable', 'boolean'],
        ]);

        $isSensitive = $request->boolean('is_sensitive');
        if ($isSensitive && ! $request->user()->canInTenant('guest_notes.sensitive.view', $this->context->tenant())) {
            abort(403);
        }

        $guest->notes()->create([
            'tenant_id' => $guest->tenant_id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'is_sensitive' => $isSensitive,
        ]);

        return back()->with('success', __('Notiz gespeichert.'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->audit->log('guests.exported', null, null, null, ['by' => $request->user()->email]);

        $guests = Guest::query()->where('anonymized', false)->orderBy('last_name')->get();

        return response()->streamDownload(function () use ($guests) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Besuche', 'No-Shows', 'VIP', 'Marketing-Einwilligung', 'Letzter Besuch'], ';');
            foreach ($guests as $g) {
                fputcsv($out, [
                    $g->first_name, $g->last_name, $g->email, $g->phone,
                    $g->visit_count, $g->no_show_count, $g->is_vip ? 'ja' : 'nein',
                    $g->marketing_consent ? 'ja' : 'nein',
                    $g->last_visit_at?->format('d.m.Y'),
                ], ';');
            }
            fclose($out);
        }, 'gaeste.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function exportSingle(Guest $guest)
    {
        $this->audit->log('guest.data_export', $guest);

        return response()->json($this->privacy->export($guest), 200, [
            'Content-Disposition' => 'attachment; filename="gast_'.$guest->id.'_dsgvo_export.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function anonymize(Request $request, Guest $guest)
    {
        $this->privacy->anonymize($guest);

        return redirect()->route('admin.guests.index')
            ->with('success', __('Gast wurde anonymisiert.'));
    }
}

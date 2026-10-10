<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tags für Reservierungen. Die Seite Einstellungen › Buchungen › Tags und die
 * Tag-Auswahl in den Reservierungsdetails nutzen dieselben Wege: Die
 * Antworten tragen id, name und color für die Auswahl und message für die Seite.
 */
class TagController extends Controller
{
    use RespondsToSettingsForms;

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
        $validated = $request->validate($this->rules(), $this->messages());

        // firstOrCreate fängt zwei gleichzeitige Anfragen mit demselben Namen
        // selbst ab (createOrFirst mit Savepoint auf dem eindeutigen Index aus
        // Mandant, Name und Bereich): Die zweite bekommt den Tag der ersten.
        $tag = Tag::firstOrCreate(
            ['tenant_id' => $this->context->tenantId(), 'name' => $validated['name'], 'scope' => 'reservation'],
            ['color' => $validated['color']]
        );

        if ($tag->wasRecentlyCreated) {
            $this->audit->log('tag.created', $tag);
            $message = __('Tag „:name“ angelegt.', ['name' => $tag->name]);
        } elseif ($tag->is_system) {
            $message = __('Einen Tag „:name“ gibt es schon. Er ist fest eingerichtet und behält seine Farbe.', ['name' => $tag->name]);
        } else {
            $tag->update(['color' => $validated['color']]);
            $this->audit->log('tag.updated', $tag);
            $message = __('Einen Tag „:name“ gab es schon; er hat jetzt die gewählte Farbe.', ['name' => $tag->name]);
        }

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'message' => $message,
        ], $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Tag $tag): mixed
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403, __('Dieser Tag ist fest eingerichtet und lässt sich nicht ändern.'));

        $validated = $request->validate($this->rules(), $this->messages());

        $taken = __('Einen Tag „:name“ gibt es schon. Bitte einen anderen Namen wählen.', ['name' => $validated['name']]);
        $exists = Tag::where('tenant_id', $tag->tenant_id)
            ->where('scope', $tag->scope)
            ->where('name', $validated['name'])
            ->whereKeyNot($tag->id)
            ->exists();
        if ($exists) {
            return $this->failed($request, 'name', $taken);
        }

        try {
            // Die Transaktion hält die Anfrage nach einem Konflikt benutzbar
            // (als Savepoint, wenn schon eine Transaktion läuft).
            DB::transaction(fn () => $tag->update(['name' => $validated['name'], 'color' => $validated['color']]));
        } catch (UniqueConstraintViolationException) {
            // Ein anderer Tag hat den Namen im selben Moment bekommen.
            return $this->failed($request, 'name', $taken);
        }
        $this->audit->log('tag.updated', $tag);

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'message' => __('Tag „:name“ gespeichert.', ['name' => $tag->name]),
        ]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403, __('Dieser Tag ist fest eingerichtet und lässt sich nicht löschen.'));

        // Ein zweiter Löschauftrag für denselben Tag (Doppelklick, zweites
        // Fenster) trifft keine Zeile mehr und schreibt keinen Eintrag ins Protokoll.
        DB::transaction(function () use ($tag): void {
            $deleted = Tag::whereKey($tag->id)->where('tenant_id', $tag->tenant_id)->delete();
            if ($deleted === 1) {
                $this->audit->log('tag.deleted', $tag, ['name' => $tag->name, 'color' => $tag->color]);
            }
        });

        return response()->json(['ok' => true, 'message' => __('Tag „:name“ gelöscht.', ['name' => $tag->name])]);
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

    /**
     * Regeln für Name und Farbe. Die Länge des Namens kommt aus den
     * Einstellungen (swayy.ui.tag_name_max); die Schreibweise #rrggbb
     * gibt das Farbfeld des Browsers vor.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.(int) config('swayy.ui.tag_name_max')],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Ursache und den nächsten Schritt.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        $name = __('Bitte gib dem Tag einen Namen.');
        $color = __('Bitte wähle eine Farbe für den Tag.');

        return [
            'name.required' => $name,
            'name.string' => $name,
            'name.max' => __('Der Name darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
            'color.required' => $color,
            'color.string' => $color,
            'color.regex' => __('Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.'),
        ];
    }
}

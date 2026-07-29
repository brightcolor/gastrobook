@extends('layouts.admin')
@section('title', 'Events')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Events – {{ $location->name }}</h1>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
            <table class="w-full min-w-[42rem] text-sm">
                <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3">Event</th>
                        <th class="px-4 py-3">Datum</th>
                        <th class="px-4 py-3">Tickets</th>
                        <th class="px-4 py-3">Preis</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Sichtbar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-50 [&>tr:hover]:bg-stone-50/70">
                    @forelse($events as $event)
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.events.show', $event) }}" class="font-semibold hover:underline">{{ $event->title }}</a>
                            </td>
                            <td class="px-4 py-3">{{ $event->starts_at->setTimezone($location->timezone)->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $event->confirmed_tickets }} / {{ $event->capacity }}</td>
                            <td class="px-4 py-3">{{ $event->price_minor ? number_format($event->price_minor / 100, 2, ',', '.') . ' €' : '–' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                                    {{ ['published' => 'bg-emerald-100 text-emerald-800', 'draft' => 'bg-stone-100 text-stone-600', 'cancelled' => 'bg-red-100 text-red-700', 'completed' => 'bg-stone-100 text-stone-500'][$event->status] ?? '' }}">
                                    {{ ['published' => 'Veröffentlicht', 'draft' => 'Entwurf', 'cancelled' => 'Abgesagt', 'completed' => 'Beendet'][$event->status] ?? $event->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ $event->is_public ? '🌐 öffentlich' : '🔒 intern' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-stone-500">Noch keine Events.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $events->links() }}</div>

        <div class="mt-4 rounded-2xl bg-white p-4 text-sm shadow-sm">
            Öffentliche Eventseite:
            <a href="{{ route('events.index', [$location->tenant->slug, $location->slug]) }}" target="_blank"
               class="font-mono text-teal-700 underline">{{ route('events.index', [$location->tenant->slug, $location->slug]) }}</a>
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="font-bold">Event anlegen</h2>
        <form method="POST" action="{{ route('admin.events.store') }}" enctype="multipart/form-data" class="mt-3 space-y-3 text-sm">
            @csrf
            <input type="text" name="title" required placeholder="Titel *" class="w-full rounded-lg border-stone-200">
            <textarea name="description" rows="3" placeholder="Beschreibung" class="w-full rounded-lg border-stone-200"></textarea>
            <div class="grid grid-cols-3 gap-2">
                <input type="date" name="date" required class="col-span-1 rounded-lg border-stone-200">
                <input type="time" name="start_time" required value="19:00" class="rounded-lg border-stone-200">
                <input type="time" name="end_time" required value="23:00" class="rounded-lg border-stone-200">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="mb-1 block text-xs text-stone-500">Kapazität *<span class="tip" tabindex="0" data-tip="Wie viele Plätze es insgesamt gibt. Ist die Zahl erreicht, zeigt die Seite „ausgebucht“ und es kann niemand mehr buchen. Nachträglich kannst du sie nicht unter die schon verkauften Tickets senken.">?</span></label>
                    <input type="number" name="capacity" required min="1" value="30" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Preis p. P. (€)<span class="tip" tabindex="0" data-tip="Der Gesamtpreis je Person. Leer lassen, wenn das Event kostenlos ist oder vor Ort abgerechnet wird.">?</span></label>
                    <input type="number" name="price" step="0.01" min="0" class="w-full rounded-lg border-stone-200"></div>
            </div>
            <div>
                <label class="mb-1 block text-xs text-stone-500">Anzahlung p. P. (€, optional)<span class="tip" tabindex="0" data-tip="Statt des vollen Preises wird online nur dieser Teilbetrag eingezogen, der Rest beim Event bezahlt. Senkt die Hemmschwelle zu buchen und schützt trotzdem vor Absagen in letzter Minute.">?</span></label>
                <input type="number" name="deposit" step="0.01" min="0" class="w-full rounded-lg border-stone-200"
                       title="Wird online eingezogen; der Rest wird beim Event bezahlt. Leer lassen, wenn der volle Preis sofort fällig ist.">
                <p class="mt-1 text-xs text-stone-400">Leer = voller Preis wird sofort fällig. Mit Anzahlung zahlt der Gast online nur diesen Teil, den Rest beim Event.</p>
            </div>
            <div>
                <label class="mb-1 block text-xs text-stone-500">Bild (optional)<span class="tip" tabindex="0" data-tip="Ein einladendes Foto oben auf der öffentlichen Event-Seite. Querformat wirkt am besten. Ein neues Bild ersetzt das bisherige.">?</span></label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold hover:file:bg-stone-200">
                <p class="mt-1 text-xs text-stone-400">Erscheint auf der öffentlichen Event-Seite. JPG, PNG oder WebP, max. 4 MB.</p>
            </div>
            <div>
                <label class="mb-1 block text-xs text-stone-500">Raum (optional)<span class="tip" tabindex="0" data-tip="Wenn das Event in einem bestimmten Raum stattfindet, kannst du ihn hier hinterlegen – dann ist für alle sichtbar, wo es läuft.">?</span></label>
                <select name="room_id" class="w-full rounded-lg border-stone-200">
                    <option value="">–</option>
                    @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->name }}</option>@endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="mb-1 block text-xs text-stone-500">Buchbar bis (Std. vorher)<span class="tip" tabindex="0" data-tip="Wie viele Stunden vor Beginn die letzte Buchung möglich ist. Bei 24 ist am Vortag Schluss – so hast du Planungssicherheit für Einkauf und Personal.">?</span></label>
                    <input type="number" name="booking_deadline_hours" min="0" placeholder="z. B. 24" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Storno bis (Std. vorher)<span class="tip" tabindex="0" data-tip="Bis wann Gäste selbst absagen dürfen. Danach ist der Storno-Link inaktiv und sie müssen sich bei euch melden.">?</span></label>
                    <input type="number" name="cancellation_deadline_hours" min="0" placeholder="z. B. 48" class="w-full rounded-lg border-stone-200"></div>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_public" value="1" checked> Öffentlich sichtbar<span class="tip" tabindex="0" data-tip="Angehakt erscheint das Event auf eurer öffentlichen Event-Seite und kann von jedem gebucht werden. Ohne Haken bleibt es intern – ihr könnt Gäste dann selbst eintragen.">?</span></label>
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Event anlegen</button>
        </form>
    </div>
</div>
@endsection

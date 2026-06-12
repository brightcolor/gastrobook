@extends('layouts.admin')
@section('title', 'Events')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Events – {{ $location->name }}</h1>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-100 text-left text-xs uppercase text-stone-500">
                    <tr>
                        <th class="px-4 py-3">Event</th>
                        <th class="px-4 py-3">Datum</th>
                        <th class="px-4 py-3">Tickets</th>
                        <th class="px-4 py-3">Preis</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Sichtbar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-50">
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

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Event anlegen</h2>
        <form method="POST" action="{{ route('admin.events.store') }}" class="mt-3 space-y-3 text-sm">
            @csrf
            <input type="text" name="title" required placeholder="Titel *" class="w-full rounded-lg border-stone-200">
            <textarea name="description" rows="3" placeholder="Beschreibung" class="w-full rounded-lg border-stone-200"></textarea>
            <div class="grid grid-cols-3 gap-2">
                <input type="date" name="date" required class="col-span-1 rounded-lg border-stone-200">
                <input type="time" name="start_time" required value="19:00" class="rounded-lg border-stone-200">
                <input type="time" name="end_time" required value="23:00" class="rounded-lg border-stone-200">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="mb-1 block text-xs text-stone-500">Kapazität *</label>
                    <input type="number" name="capacity" required min="1" value="30" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Preis p. P. (€)</label>
                    <input type="number" name="price" step="0.01" min="0" class="w-full rounded-lg border-stone-200"></div>
            </div>
            <div>
                <label class="mb-1 block text-xs text-stone-500">Raum (optional)</label>
                <select name="room_id" class="w-full rounded-lg border-stone-200">
                    <option value="">–</option>
                    @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->name }}</option>@endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="mb-1 block text-xs text-stone-500">Buchbar bis (Std. vorher)</label>
                    <input type="number" name="booking_deadline_hours" min="0" placeholder="z. B. 24" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Storno bis (Std. vorher)</label>
                    <input type="number" name="cancellation_deadline_hours" min="0" placeholder="z. B. 48" class="w-full rounded-lg border-stone-200"></div>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_public" value="1" checked> Öffentlich sichtbar</label>
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Event anlegen</button>
        </form>
    </div>
</div>
@endsection

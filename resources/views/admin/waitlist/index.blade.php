@extends('layouts.admin')
@section('title', 'Warteliste')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Warteliste</h1>
    <form method="GET">
        <input type="date" name="date" value="{{ $date }}" class="rounded-lg border-stone-200 text-sm" onchange="this.form.submit()">
    </form>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="space-y-3">
            @forelse($entries as $entry)
                <div class="rounded-2xl bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <strong>{{ $entry->guest_name }}</strong> · {{ $entry->party_size }} P.
                            @if($entry->desired_start_at)
                                · Wunsch: {{ $entry->desired_start_at->setTimezone($location->timezone)->format('H:i') }} Uhr (± {{ $entry->flex_minutes }} Min.)
                            @endif
                            <div class="text-xs text-stone-500">
                                {{ $entry->guest_email }} {{ $entry->guest_phone }} · seit {{ $entry->created_at->format('H:i') }}
                                @if($entry->note) · 📝 {{ $entry->note }} @endif
                            </div>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ ['waiting' => 'bg-amber-100 text-amber-800', 'offered' => 'bg-purple-100 text-purple-800', 'accepted' => 'bg-emerald-100 text-emerald-800', 'seated' => 'bg-blue-100 text-blue-800'][$entry->status] ?? 'bg-stone-100 text-stone-600' }}">
                            {{ $entry->status }}
                        </span>
                    </div>
                    @if(in_array($entry->status, ['waiting', 'offered']))
                        <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-stone-100 pt-3">
                            @if($entry->guest_email)
                                <form method="POST" action="{{ route('admin.waitlist.offer', $entry) }}" class="flex items-end gap-2">
                                    @csrf
                                    <div>
                                        <label class="mb-1 block text-xs text-stone-500">Zeit anbieten</label>
                                        <input type="time" name="time" required class="rounded-lg border-stone-200 text-sm">
                                    </div>
                                    <button class="rounded-lg bg-purple-600 px-3 py-2 text-xs font-semibold text-white">Per Mail anbieten</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.waitlist.seat', $entry) }}">
                                @csrf
                                <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Sofort platzieren</button>
                            </form>
                            <form method="POST" action="{{ route('admin.waitlist.cancel', $entry) }}">
                                @csrf
                                <button class="rounded-lg bg-red-100 px-3 py-2 text-xs font-semibold text-red-700">Entfernen</button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-2xl bg-white p-8 text-center text-stone-500 shadow-sm">Keine Wartelisteneinträge für diesen Tag.</div>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Gast hinzufügen</h2>
        <form method="POST" action="{{ route('admin.waitlist.store') }}" class="mt-3 space-y-3 text-sm">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="text" name="name" required placeholder="Name *" class="w-full rounded-lg border-stone-200">
            <div class="grid grid-cols-2 gap-2">
                <input type="number" name="party_size" required min="1" placeholder="Personen *" class="rounded-lg border-stone-200">
                <input type="time" name="time" class="rounded-lg border-stone-200">
            </div>
            <input type="email" name="email" placeholder="E-Mail (für Angebote)" class="w-full rounded-lg border-stone-200">
            <input type="tel" name="phone" placeholder="Telefon" class="w-full rounded-lg border-stone-200">
            <input type="text" name="note" placeholder="Notiz" class="w-full rounded-lg border-stone-200">
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Hinzufügen</button>
        </form>
    </div>
</div>
@endsection

@extends('layouts.admin')
@section('title', $guest->fullName())
@section('content')
<div class="mb-5">
    <a href="{{ route('admin.guests.index') }}" class="text-sm text-stone-500 hover:underline">← Gästeliste</a>
    <h1 class="text-2xl font-bold">{{ $guest->fullName() }} @if($guest->is_vip)⭐@endif</h1>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        {{-- Profile form --}}
        <form method="POST" action="{{ route('admin.guests.update', $guest) }}" class="rounded-2xl bg-white p-5 shadow-sm">
            @csrf @method('PUT')
            <h2 class="mb-3 font-bold">Profil</h2>
            <div class="grid gap-3 text-sm sm:grid-cols-2">
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Vorname</label>
                    <input type="text" name="first_name" value="{{ $guest->first_name }}" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Nachname *</label>
                    <input type="text" name="last_name" required value="{{ $guest->last_name }}" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">E-Mail</label>
                    <input type="email" name="email" value="{{ $guest->email }}" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Telefon</label>
                    <input type="tel" name="phone" value="{{ $guest->phone }}" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Geburtstag</label>
                    <input type="date" name="birthday" value="{{ $guest->birthday?->toDateString() }}" class="w-full rounded-lg border-stone-200"></div>
                <div class="flex items-end"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_vip" value="1" @checked($guest->is_vip)> VIP-Gast</label></div>
                <div class="sm:col-span-2"><label class="mb-1 block text-xs font-semibold text-stone-500">Präferenzen</label>
                    <input type="text" name="preferences" value="{{ $guest->preferences }}" placeholder="z. B. Fensterplatz, Terrasse" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Allergien</label>
                    <input type="text" name="allergies" value="{{ $guest->allergies }}" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Barrierefreiheit</label>
                    <input type="text" name="accessibility_notes" value="{{ $guest->accessibility_notes }}" class="w-full rounded-lg border-stone-200"></div>
            </div>
            <button class="mt-4 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
        </form>

        {{-- Reservation history --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Reservierungshistorie</h2>
            <div class="divide-y divide-stone-50 text-sm">
                @forelse($reservations as $r)
                    <a href="{{ route('admin.reservations.show', $r) }}" class="flex items-center justify-between py-2 hover:bg-stone-50">
                        <span>{{ $r->localStart()->format('d.m.Y H:i') }} · {{ $r->party_size }} P. · {{ $r->code }}</span>
                        <x-status-badge :status="$r->status" />
                    </a>
                @empty
                    <p class="py-2 text-stone-500">Noch keine Reservierungen.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Stats --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm text-sm">
            <h2 class="mb-3 font-bold">Statistik</h2>
            <dl class="space-y-1.5">
                <div class="flex justify-between"><dt class="text-stone-500">Besuche</dt><dd class="font-bold">{{ $guest->visit_count }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">No-Shows</dt><dd class="font-bold text-red-600">{{ $guest->no_show_count }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Stornierungen</dt><dd class="font-bold">{{ $guest->cancellation_count }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Ø Gruppengröße</dt><dd class="font-bold">{{ $guest->avg_party_size ?? '–' }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Letzter Besuch</dt><dd class="font-bold">{{ $guest->last_visit_at?->format('d.m.Y') ?? '–' }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Marketing-Einwilligung</dt><dd class="font-bold">{{ $guest->marketing_consent ? '✓ ja' : '✗ nein' }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-500">Quelle</dt><dd>{{ $guest->source }}</dd></div>
            </dl>
        </div>

        {{-- Notes --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Notizen</h2>
            <form method="POST" action="{{ route('admin.guests.notes', $guest) }}" class="mb-3 space-y-2">
                @csrf
                <textarea name="body" rows="2" required placeholder="Neue Notiz…" class="w-full rounded-lg border-stone-200 text-sm"></textarea>
                @if(auth()->user()->canInTenant('guest_notes.sensitive.view', app(\App\Support\TenantContext::class)->tenant()))
                    <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="is_sensitive" value="1"> Sensibel (eingeschränkte Sicht)</label>
                @endif
                <button class="rounded-lg bg-stone-900 px-3 py-1.5 text-xs font-bold text-white">Speichern</button>
            </form>
            <div class="space-y-2 text-sm">
                @foreach($notes as $note)
                    <div class="rounded-lg {{ $note->is_sensitive ? 'bg-red-50' : 'bg-stone-50' }} p-2.5">
                        @if($note->is_sensitive)<span class="text-xs font-semibold text-red-600">🔒 sensibel</span>@endif
                        {{ $note->body }}
                        <div class="mt-1 text-xs text-stone-400">{{ $note->user?->name }} · {{ $note->created_at->format('d.m.Y') }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- GDPR --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Datenschutz (DSGVO)</h2>
            <div class="space-y-2">
                @if(auth()->user()->canInTenant('guests.export', app(\App\Support\TenantContext::class)->tenant()))
                    <a href="{{ route('admin.guests.export-single', $guest) }}" class="block rounded-xl bg-stone-100 px-4 py-2.5 text-center text-sm font-semibold hover:bg-stone-200">Datenexport (Art. 15/20)</a>
                @endif
                @if(auth()->user()->canInTenant('guests.anonymize', app(\App\Support\TenantContext::class)->tenant()) && ! $guest->anonymized)
                    <form method="POST" action="{{ route('admin.guests.anonymize', $guest) }}"
                          onsubmit="return confirm('Gast unwiderruflich anonymisieren? Alle persönlichen Daten werden entfernt.')">
                        @csrf
                        <button class="w-full rounded-xl bg-red-100 px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-200">Anonymisieren (Art. 17)</button>
                    </form>
                @endif
            </div>
            @if($guest->consents->isNotEmpty())
                <h3 class="mt-4 text-xs font-bold uppercase text-stone-500">Einwilligungshistorie</h3>
                <div class="mt-1 space-y-1 text-xs text-stone-600">
                    @foreach($guest->consents->sortByDesc('recorded_at')->take(10) as $consent)
                        <div>{{ $consent->recorded_at?->format('d.m.Y H:i') }} · {{ $consent->type }}: {{ $consent->granted ? '✓ erteilt' : '✗ widerrufen' }} ({{ $consent->channel }})</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

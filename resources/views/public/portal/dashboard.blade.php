@extends('layouts.public', ['tenant' => $tenant])
@section('title', 'Meine Termine – ' . $tenant->name)
@section('content')
<div class="mx-auto max-w-2xl">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">Hallo {{ $guest->first_name ?: $guest->last_name }}</h1>
        <form method="POST" action="{{ route('guest.portal.logout', $tenant->slug) }}">
            @csrf
            <button class="text-sm text-stone-500 underline">Abmelden</button>
        </form>
    </div>

    @if($reservations->isEmpty())
        <p class="rounded-2xl bg-white p-6 text-center text-stone-500 shadow-sm">Noch keine Buchungen.</p>
    @else
        <div class="space-y-3">
            @foreach($reservations as $r)
                @php($past = $r->start_at->isPast())
                <div class="rounded-2xl bg-white p-4 shadow-sm {{ $past ? 'opacity-60' : '' }}">
                    <div class="flex items-baseline justify-between">
                        <div class="font-bold">{{ $r->localStart()->format('d.m.Y · H:i') }} Uhr</div>
                        <span class="text-xs text-stone-400">{{ $r->code }}</span>
                    </div>
                    <div class="mt-1 text-sm text-stone-600">
                        @if($tenant->isSalon())
                            {{ $r->services->pluck('name')->join(', ') ?: 'Termin' }}@if($r->staffMember) · {{ $r->staffMember->name }}@endif
                        @else
                            {{ $r->party_size }} {{ $r->party_size === 1 ? 'Person' : 'Personen' }}
                        @endif
                        · <span class="font-semibold">{{ $r->status->label() }}</span>
                    </div>
                    @if(! $past && $r->status->isActive())
                        <div class="mt-3 flex gap-2 text-sm">
                            <a href="{{ route('booking.reschedule', ['code' => $r->code, 'token' => $r->manage_token]) }}"
                               class="rounded-lg border-2 border-stone-200 px-3 py-1.5 font-semibold hover:border-brand">Umbuchen</a>
                            <a href="{{ route('booking.manage', ['code' => $r->code, 'token' => $r->manage_token]) }}"
                               class="rounded-lg border-2 border-stone-200 px-3 py-1.5 font-semibold hover:border-brand">Verwalten</a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection

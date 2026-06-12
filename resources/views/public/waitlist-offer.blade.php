@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Tisch verfügbar')
@section('content')
<div class="rounded-2xl bg-white p-6 shadow-sm">
    @if($offer && $offer->status === 'open' && $offer->offer_expires_at->isFuture())
        <div class="text-center text-5xl">🎉</div>
        <h1 class="mt-3 text-center text-2xl font-bold">Ein Tisch ist frei!</h1>
        <div class="mt-4 space-y-2 rounded-xl bg-stone-50 p-4 text-sm">
            <div class="flex justify-between"><span class="text-stone-500">Restaurant</span><strong>{{ $location->name }}</strong></div>
            <div class="flex justify-between"><span class="text-stone-500">Datum</span><strong>{{ $offer->offered_start_at->setTimezone($location->timezone)->format('d.m.Y') }}</strong></div>
            <div class="flex justify-between"><span class="text-stone-500">Uhrzeit</span><strong>{{ $offer->offered_start_at->setTimezone($location->timezone)->format('H:i') }} Uhr</strong></div>
            <div class="flex justify-between"><span class="text-stone-500">Personen</span><strong>{{ $entry->party_size }}</strong></div>
            <div class="flex justify-between"><span class="text-stone-500">Gültig bis</span><strong>{{ $offer->offer_expires_at->setTimezone($location->timezone)->format('H:i') }} Uhr</strong></div>
        </div>
        <form method="POST" action="{{ route('waitlist.respond.post', ['entry' => $entry->id, 'token' => $entry->manage_token]) }}" class="mt-6 grid grid-cols-2 gap-3">
            @csrf
            <button name="decision" value="accept" class="rounded-xl bg-emerald-600 py-3 font-bold text-white hover:bg-emerald-700">Annehmen</button>
            <button name="decision" value="decline" class="rounded-xl bg-stone-200 py-3 font-bold text-stone-700 hover:bg-stone-300">Ablehnen</button>
        </form>
    @else
        <h1 class="text-center text-xl font-bold">Kein aktives Angebot</h1>
        <p class="mt-2 text-center text-sm text-stone-600">
            Status Ihres Wartelisteneintrags: <strong>{{ $entry->status }}</strong>
        </p>
    @endif
</div>
@endsection

@extends('layouts.public')
@section('title', $event->title . ' – ' . $location->name)
@section('content')
@php($startLocal = $event->starts_at->copy()->setTimezone($location->timezone))
<div class="rounded-2xl bg-white p-6 shadow-sm">
    <p class="text-sm"><a href="{{ route('events.index', [$tenant->slug, $location->slug]) }}" class="text-stone-500 underline">← Alle Events</a></p>
    <h1 class="mt-2 text-2xl font-bold">{{ $event->title }}</h1>
    <p class="mt-1 text-stone-600">{{ $startLocal->format('d.m.Y') }} · {{ $startLocal->format('H:i') }} Uhr · {{ $location->name }}</p>
    @if($event->price_minor)
        <p class="mt-1 text-lg font-bold">{{ number_format($event->price_minor / 100, 2, ',', '.') }} € <span class="text-sm font-normal text-stone-500">pro Person</span></p>
    @endif
    @if($event->description)
        <p class="mt-4 whitespace-pre-line text-sm text-stone-700">{{ $event->description }}</p>
    @endif

    @if(!$bookable)
        <div class="mt-6 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700">
            @if($remaining <= 0) Dieses Event ist ausgebucht.
            @elseif($event->booking_deadline_at && now()->gte($event->booking_deadline_at)) Die Buchungsfrist ist abgelaufen.
            @else Dieses Event ist nicht mehr buchbar. @endif
        </div>
    @else
        @if($remaining <= 10)
            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">Nur noch {{ $remaining }} Plätze verfügbar!</p>
        @endif

        <form method="POST" action="{{ route('events.store', [$tenant->slug, $location->slug, $event->slug]) }}" class="mt-6 space-y-4">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div>
                <label class="mb-1 block text-sm font-semibold">Anzahl Tickets *</label>
                <select name="ticket_count" required class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    @for($i = 1; $i <= min(10, $remaining); $i++)
                        <option value="{{ $i }}" @selected(old('ticket_count') == $i)>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">Name *</label>
                <input type="text" name="name" required value="{{ old('name') }}" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">E-Mail *</label>
                <input type="email" name="email" required value="{{ old('email') }}" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">Telefon (optional)</label>
                <input type="tel" name="phone" value="{{ old('phone') }}" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">Anmerkung (optional)</label>
                <textarea name="note" rows="2" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
            </div>
            <div class="space-y-2 border-t border-stone-100 pt-4 text-sm">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="privacy_accepted" value="1" required class="mt-1">
                    <span>Ich akzeptiere die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif. *</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="newsletter" value="1" class="mt-1">
                    <span>Newsletter mit Angeboten und Veranstaltungen erhalten (jederzeit widerrufbar).</span>
                </label>
            </div>
            @if($event->price_minor)
                <p class="rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
                    💶 Die Vorauszahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
                    Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
                </p>
            @endif
            <button class="btn-brand w-full rounded-xl py-4 text-lg font-bold text-white shadow hover:opacity-90">
                Jetzt buchen{{ $event->price_minor ? ' · ' . number_format($event->price_minor / 100, 2, ',', '.') . ' € p. P.' : '' }}
            </button>
        </form>
    @endif
</div>
@endsection

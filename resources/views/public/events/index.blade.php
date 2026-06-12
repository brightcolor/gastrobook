@extends('layouts.public')
@section('title', 'Events – ' . $location->name)
@section('content')
<div class="rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-center text-2xl font-bold">Events bei {{ $location->name }}</h1>
    <p class="mt-1 text-center text-sm text-stone-500">
        <a href="{{ route('booking.show', [$tenant->slug, $location->slug]) }}" class="underline">← Tisch reservieren</a>
    </p>

    <div class="mt-6 space-y-4">
        @forelse($events as $event)
            @php($startLocal = $event->starts_at->copy()->setTimezone($location->timezone))
            <a href="{{ route('events.show', [$tenant->slug, $location->slug, $event->slug]) }}"
               class="block rounded-2xl border-2 border-stone-100 p-4 transition hover:border-brand">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold">{{ $event->title }}</h2>
                        <p class="mt-0.5 text-sm text-stone-600">{{ $startLocal->format('d.m.Y') }} · {{ $startLocal->format('H:i') }} Uhr</p>
                        @if($event->description)
                            <p class="mt-1 line-clamp-2 text-sm text-stone-500">{{ Str::limit($event->description, 140) }}</p>
                        @endif
                    </div>
                    <div class="shrink-0 text-right">
                        @if($event->price_minor)
                            <div class="font-bold">{{ number_format($event->price_minor / 100, 2, ',', '.') }} €</div>
                        @endif
                        @if($event->remainingCapacity() <= 0)
                            <span class="mt-1 inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Ausgebucht</span>
                        @elseif($event->remainingCapacity() <= 10)
                            <span class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Nur noch {{ $event->remainingCapacity() }} Plätze</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <p class="text-center text-stone-500">Aktuell sind keine Events geplant.</p>
        @endforelse
    </div>
</div>
@endsection

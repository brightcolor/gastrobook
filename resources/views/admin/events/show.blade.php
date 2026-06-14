@extends('layouts.admin')
@section('title', $event->title)
@section('content')
@php($startLocal = $event->starts_at->setTimezone($location->timezone))
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <a href="{{ route('admin.events.index') }}" class="text-sm text-stone-500 hover:underline">← Alle Events</a>
        <h1 class="text-2xl font-bold">{{ $event->title }}</h1>
        <p class="text-stone-600">{{ $startLocal->format('d.m.Y H:i') }} Uhr · {{ $confirmedTickets }} / {{ $event->capacity }} Tickets</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.events.attendees', $event) }}" class="rounded-xl bg-stone-200 px-4 py-2.5 text-sm font-semibold hover:bg-stone-300">Teilnehmerliste (CSV)</a>
        <form method="POST" action="{{ route('admin.events.status', $event) }}">
            @csrf @method('PUT')
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-stone-200 text-sm">
                @foreach(['draft' => 'Entwurf', 'published' => 'Veröffentlicht', 'cancelled' => 'Abgesagt', 'completed' => 'Beendet'] as $value => $label)
                    <option value="{{ $value }}" @selected($event->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
    <table class="w-full min-w-[42rem] text-sm">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
            <tr>
                <th class="px-4 py-3">Buchungsnr.</th>
                <th class="px-4 py-3">Gast</th>
                <th class="px-4 py-3">Tickets</th>
                <th class="px-4 py-3">Zahlung</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Aktionen</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50 [&>tr:hover]:bg-stone-50/70">
            @forelse($bookings as $booking)
                <tr class="{{ $booking->status === 'cancelled' ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3 font-mono text-xs">{{ $booking->code }}</td>
                    <td class="px-4 py-3">
                        <strong>{{ $booking->guest_name }}</strong>
                        <div class="text-xs text-stone-500">{{ $booking->guest_email }} {{ $booking->guest_phone }}</div>
                        @if($booking->note)<div class="text-xs text-stone-400">📝 {{ $booking->note }}</div>@endif
                    </td>
                    <td class="px-4 py-3">{{ $booking->ticket_count }}</td>
                    <td class="px-4 py-3 text-xs">
                        {{ $booking->amount_minor ? number_format($booking->amount_minor / 100, 2, ',', '.') . ' € · ' : '' }}{{ $booking->payment_status }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                            {{ ['confirmed' => 'bg-emerald-100 text-emerald-800', 'checked_in' => 'bg-blue-100 text-blue-800', 'cancelled' => 'bg-red-100 text-red-700'][$booking->status] ?? '' }}">
                            {{ ['confirmed' => 'Bestätigt', 'checked_in' => 'Eingecheckt', 'cancelled' => 'Storniert'][$booking->status] ?? $booking->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-1.5">
                            @if($booking->status === 'confirmed')
                                <form method="POST" action="{{ route('admin.events.check-in', $booking) }}">
                                    @csrf
                                    <button class="rounded-lg bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white">Check-in</button>
                                </form>
                                <form method="POST" action="{{ route('admin.events.cancel-booking', $booking) }}" onsubmit="return confirm('Buchung stornieren?')">
                                    @csrf
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Stornieren</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-stone-500">Noch keine Buchungen.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection

@extends('layouts.admin')
@section('title', 'Rückerstattungen')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Rückerstattungen</h1>

@php
    $badges = [
        'pending' => 'bg-amber-100 text-amber-800',
        'approved' => 'bg-blue-100 text-blue-800',
        'processing' => 'bg-blue-100 text-blue-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-red-100 text-red-800',
        'rejected' => 'bg-stone-200 text-stone-600',
    ];
    $labels = [
        'pending' => 'Freigabe offen', 'approved' => 'freigegeben', 'processing' => 'in Bearbeitung',
        'completed' => 'erstattet', 'failed' => 'fehlgeschlagen', 'rejected' => 'abgelehnt',
    ];
@endphp

@if($refunds->isEmpty())
    <p class="rounded-2xl bg-white p-6 text-center text-sm text-stone-500 shadow-sm">Keine Rückerstattungen vorhanden.</p>
@else
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
        <table class="w-full min-w-[42rem] text-sm">
            <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-3">Reservierung</th>
                    <th class="px-4 py-3">Betrag</th>
                    <th class="px-4 py-3">Anbieter</th>
                    <th class="px-4 py-3">Quelle</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Angelegt</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="[&>tr:hover]:bg-stone-50/70">
                @foreach($refunds as $refund)
                    <tr class="border-b border-stone-50">
                        <td class="px-4 py-3">
                            @if($refund->reservation)
                                <a href="{{ route('admin.reservations.show', $refund->reservation_id) }}" class="font-mono text-teal-700 underline">{{ $refund->reservation->code }}</a>
                                <div class="text-xs text-stone-400">{{ $refund->reservation->guest_name_snapshot }}</div>
                            @else
                                <span class="text-stone-400">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold">{{ $refund->amountFormatted() }}</td>
                        <td class="px-4 py-3 capitalize">{{ $refund->provider }}</td>
                        <td class="px-4 py-3 text-xs text-stone-500">{{ $refund->source }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badges[$refund->status] ?? 'bg-stone-100' }}">
                                {{ $labels[$refund->status] ?? $refund->status }}
                            </span>
                            @if($refund->status === 'failed' && $refund->error)
                                <div class="mt-1 text-xs text-red-600">{{ $refund->error }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-stone-500">{{ $refund->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($refund->status === 'pending')
                                <form method="POST" action="{{ route('admin.refunds.approve', $refund) }}" class="inline">
                                    @csrf
                                    <button class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-700">Freigeben</button>
                                </form>
                                <form method="POST" action="{{ route('admin.refunds.reject', $refund) }}" class="inline"
                                      onsubmit="return confirm('Rückerstattung ablehnen?')">
                                    @csrf
                                    <button class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">Ablehnen</button>
                                </form>
                            @elseif($refund->status === 'failed')
                                <form method="POST" action="{{ route('admin.refunds.retry', $refund) }}" class="inline">
                                    @csrf
                                    <button class="rounded-lg border border-stone-200 px-3 py-1.5 text-xs hover:border-teal-600">Erneut versuchen</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $refunds->links() }}</div>
@endif
@endsection

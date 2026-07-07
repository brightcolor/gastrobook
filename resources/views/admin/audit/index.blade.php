@extends('layouts.admin')
@section('title', 'Auditlog')
@section('content')
<h1 class="mb-1 text-2xl font-bold">Änderungsprotokoll</h1>
<p class="mb-5 text-sm text-stone-500">Wer hat wann was geändert – vom alten auf den neuen Wert.</p>

<x-active-filters :reset="route('admin.audit.index')" :filters="[
    'Aktion' => request('action'),
    'Von'    => request('from'),
    'Bis'    => request('until'),
]" />

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100 text-sm">
    <input type="text" name="action" value="{{ request('action') }}" placeholder="Suche (z. B. Reservierung)" class="rounded-lg border-stone-200">
    <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-stone-200">
    <input type="date" name="until" value="{{ request('until') }}" class="rounded-lg border-stone-200">
    <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Filtern</button>
</form>

<div class="space-y-2.5">
    @forelse($logs as $log)
        @php($changes = $log->fieldChanges())
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="font-semibold text-stone-800">{{ $log->actionLabel() }}</span>
                    @if($log->entity_id)
                        <span class="text-sm text-stone-400">Nr. {{ $log->entity_id }}</span>
                    @endif
                </div>
                <div class="text-xs text-stone-400">
                    {{ $log->user?->name ?? 'System' }}
                    @if($log->impersonator_id)<span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">Support</span>@endif
                    · {{ $log->created_at->copy()->setTimezone($tz)->format('d.m.Y · H:i') }} Uhr
                </div>
            </div>

            @if(!empty($changes))
                <div class="mt-3 overflow-hidden rounded-xl border border-stone-100">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-400">
                                <th class="px-3 py-1.5">Feld</th>
                                <th class="px-3 py-1.5">Vorher</th>
                                <th class="w-6 px-1 py-1.5"></th>
                                <th class="px-3 py-1.5">Nachher</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-50">
                            @foreach($changes as $c)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium text-stone-600">{{ $c['label'] }}</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['from'] !== null)
                                            <span class="rounded bg-stone-100 px-1.5 py-0.5 text-stone-500">{{ $c['from'] }}</span>
                                        @else
                                            <span class="text-xs italic text-stone-300">leer</span>
                                        @endif
                                    </td>
                                    <td class="px-1 py-1.5 text-center text-stone-300">→</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['to'] !== null)
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-800">{{ $c['to'] }}</span>
                                        @else
                                            <span class="text-xs font-semibold uppercase text-red-400">gelöscht</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-2xl bg-white p-8 text-center text-stone-500 shadow-sm ring-1 ring-stone-100">Keine Einträge.</div>
    @endforelse
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection

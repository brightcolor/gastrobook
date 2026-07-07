@extends('layouts.admin')
@section('title', 'Auditlog')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Auditlog</h1>

<x-active-filters :reset="route('admin.audit.index')" :filters="[
    'Aktion' => request('action'),
    'Von'    => request('from'),
    'Bis'    => request('until'),
]" />

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100 text-sm">
    <input type="text" name="action" value="{{ request('action') }}" placeholder="Aktion (z. B. reservation.)" class="rounded-lg border-stone-200">
    <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-stone-200">
    <input type="date" name="until" value="{{ request('until') }}" class="rounded-lg border-stone-200">
    <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Filtern</button>
</form>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
    <table class="w-full min-w-[42rem] text-sm">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
            <tr>
                <th class="px-4 py-3">Zeit</th>
                <th class="px-4 py-3">Benutzer</th>
                <th class="px-4 py-3">Aktion</th>
                <th class="px-4 py-3">Objekt</th>
                <th class="px-4 py-3">Änderungen</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50 [&>tr:hover]:bg-stone-50/70">
            @forelse($logs as $log)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2.5 text-stone-500">{{ $log->created_at->copy()->setTimezone($tz)->format('d.m.Y H:i:s') }}</td>
                    <td class="px-4 py-2.5">
                        {{ $log->user?->name ?? 'System' }}
                        @if($log->impersonator_id)<span class="rounded bg-amber-100 px-1.5 text-xs text-amber-800">Support</span>@endif
                    </td>
                    <td class="px-4 py-2.5 font-mono text-xs">{{ $log->action }}</td>
                    <td class="px-4 py-2.5 text-stone-500">{{ class_basename($log->entity_type ?? '') }} {{ $log->entity_id ? '#' . $log->entity_id : '' }}</td>
                    <td class="max-w-md px-4 py-2.5 text-xs">
                        @php($changes = $log->fieldChanges())
                        @if(empty($changes))
                            <span class="text-stone-300">—</span>
                        @else
                            @php($visible = array_slice($changes, 0, 3))
                            @php($rest = array_slice($changes, 3))
                            <ul class="space-y-0.5">
                                @foreach($visible as $c)
                                    <li class="flex flex-wrap items-baseline gap-x-1.5">
                                        <span class="font-mono text-[11px] text-stone-400">{{ $c['field'] }}:</span>
                                        @if($c['from'] !== null && $c['to'] !== null)
                                            <span class="text-stone-400 line-through decoration-stone-300">{{ $c['from'] }}</span>
                                            <span class="text-stone-300">→</span>
                                            <span class="font-semibold text-stone-700">{{ $c['to'] }}</span>
                                        @elseif($c['to'] !== null)
                                            <span class="font-semibold text-emerald-700">{{ $c['to'] }}</span>
                                        @else
                                            <span class="text-stone-400 line-through decoration-stone-300">{{ $c['from'] }}</span>
                                            <span class="text-[10px] font-semibold uppercase text-red-400">entfernt</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if($rest)
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-[11px] font-semibold text-stone-400 hover:text-stone-600">+ {{ count($rest) }} weitere</summary>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach($rest as $c)
                                            <li class="flex flex-wrap items-baseline gap-x-1.5">
                                                <span class="font-mono text-[11px] text-stone-400">{{ $c['field'] }}:</span>
                                                @if($c['from'] !== null && $c['to'] !== null)
                                                    <span class="text-stone-400 line-through decoration-stone-300">{{ $c['from'] }}</span>
                                                    <span class="text-stone-300">→</span>
                                                    <span class="font-semibold text-stone-700">{{ $c['to'] }}</span>
                                                @elseif($c['to'] !== null)
                                                    <span class="font-semibold text-emerald-700">{{ $c['to'] }}</span>
                                                @else
                                                    <span class="text-stone-400 line-through decoration-stone-300">{{ $c['from'] }}</span>
                                                    <span class="text-[10px] font-semibold uppercase text-red-400">entfernt</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-stone-500">Keine Einträge.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection

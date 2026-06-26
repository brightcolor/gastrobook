@extends('layouts.admin')
@section('title', 'Auditlog')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Auditlog</h1>

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
                    <td class="max-w-md truncate px-4 py-2.5 font-mono text-xs text-stone-400">
                        {{ $log->new_values ? json_encode($log->new_values, JSON_UNESCAPED_UNICODE) : '' }}
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

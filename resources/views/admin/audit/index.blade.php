@extends('layouts.admin')
@section('title', 'Protokoll')

@section('content')
<x-page-header title="Protokoll" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Wer wann was geändert hat, mit altem und neuem Wert. Einträge lassen sich nachträglich nicht ändern." />

<x-active-filters :reset="route('admin.audit.index')" :filters="[
    'Bereich' => $area !== '' ? $areas[$area] : null,
    'Eintrag' => $entityLabel,
    'Suche' => $filters['q'],
    'Aktion' => $filters['action'],
    'Von' => $filters['from'],
    'Bis' => $filters['until'],
]" />

<form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 text-sm">
    @if($entityLabel)
        <input type="hidden" name="entity" value="{{ $filters['entity'] }}">
        <input type="hidden" name="entity_id" value="{{ $filters['entity_id'] }}">
    @endif
    <x-field name="q" id="audit-q" label="Suchen" type="search" :value="$filters['q']" :maxlength="$searchMax" autocomplete="off"
             placeholder="Name, Wert oder Aktion" class="min-w-56 grow sm:max-w-sm" />
    <div class="space-y-1.5">
        <label for="audit-area" class="block font-semibold text-stone-800">Bereich</label>
        <select id="audit-area" name="area" class="min-w-48">
            <option value="">Alle Bereiche</option>
            @foreach($areas as $key => $label)
                <option value="{{ $key }}" @selected($area === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="audit-from" class="block font-semibold text-stone-800">Von</label>
        <input id="audit-from" type="date" name="from" value="{{ $filters['from'] }}">
    </div>
    <div class="space-y-1.5">
        <label for="audit-until" class="block font-semibold text-stone-800">Bis</label>
        <input id="audit-until" type="date" name="until" value="{{ $filters['until'] }}">
    </div>
    <button type="submit" class="btn btn-primary">Filtern</button>
</form>

<div class="space-y-2.5">
    @forelse($logs as $log)
        @php($changes = $log->fieldChanges($salon))
        <article class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    @if($log->hasActionLabel())
                        <h2 class="font-semibold text-stone-900">{{ $log->actionLabel($salon) }}</h2>
                    @else
                        <h2 class="font-semibold text-stone-900">{{ $log->area($salon) !== null ? $log->area($salon).': ' : '' }}Aktion <code class="rounded bg-stone-100 px-1.5 py-0.5 text-[0.8125rem] font-medium text-stone-800 [overflow-wrap:anywhere]">{{ $log->action }}</code></h2>
                    @endif
                    @if($log->entity_id)
                        <span class="text-sm text-stone-600">Nr. {{ $log->entity_id }}</span>
                    @endif
                </div>
                <p class="text-[0.8125rem] text-stone-600">
                    {{ $log->user?->name ?? 'System' }}
                    @if($log->impersonator_id)
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">Support</span>
                    @endif
                    · {{ $log->created_at->copy()->setTimezone($tz)->format('d.m.Y · H:i') }} Uhr
                </p>
            </div>

            @if(! empty($changes))
                <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-stone-50 text-left text-[0.75rem] font-semibold text-stone-600">
                                <th scope="col" class="px-3 py-1.5">Feld</th>
                                <th scope="col" class="px-3 py-1.5">Vorher</th>
                                <th scope="col" class="w-6 px-1 py-1.5"><span class="sr-only">wird zu</span></th>
                                <th scope="col" class="px-3 py-1.5">Nachher</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($changes as $c)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium text-stone-700">{{ $c['label'] }}</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['from'] !== null)
                                            <span class="rounded bg-stone-100 px-1.5 py-0.5 text-stone-700">{{ $c['from'] }}</span>
                                        @else
                                            <span class="text-[0.75rem] italic text-stone-600">leer</span>
                                        @endif
                                    </td>
                                    <td class="px-1 py-1.5 text-center text-stone-500" aria-hidden="true">→</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['to'] !== null)
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-800">{{ $c['to'] }}</span>
                                        @else
                                            <span class="text-[0.75rem] font-semibold text-red-700">gelöscht</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </article>
    @empty
        @if($filtered)
            <x-empty-state title="Keine passenden Einträge">
                @if($filters['q'] !== '')
                    Zu „{{ $filters['q'] }}“ gibt es im Protokoll keinen Eintrag. Prüfe die Schreibweise oder setze die Filter zurück.
                @else
                    Zu diesen Filtern gibt es im Protokoll keinen Eintrag. Lockere die Filter oder setze sie zurück.
                @endif
                <x-slot:action>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-secondary">Filter zurücksetzen</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state title="Noch keine Einträge">
                Sobald jemand im Team etwas anlegt, ändert oder löscht, steht es hier.
            </x-empty-state>
        @endif
    @endforelse
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection

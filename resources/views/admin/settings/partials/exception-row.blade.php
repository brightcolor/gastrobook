@use('App\Support\CapacityModes')
@use('App\Support\ScheduleExceptionPeriod')
{{-- Eine Ausnahme in der Liste: Kalenderblatt, Bezeichnung, je Wirkung eine Zeile mit Symbol und Wort, Zeitraum, Aktionen.
     $entry kommt aus ScheduleExceptions::entries(), $past ist wahr für vergangene Einträge (nur Löschen).
     $states, $stateLabels, $salon, $assignsTables und $capacityMode gibt die Seite mit. Das Kalenderblatt ist Zierde; der Zeitraum steht
     als Text darunter. Trägt ein Eintrag Räume oder Tische, während die Zählweise keine Tische vergibt, sagt ein Hinweis, wann sie wirken. --}}
@php
    $period = ScheduleExceptionPeriod::text($entry['from'], $entry['until']);
    $sheet = ScheduleExceptionPeriod::sheet($entry['from'], $entry['until']);
    $name = $entry['label'] ?: $period;
    $canEdit = $entry['editable'] && ! $past;
    // Sperren von Räumen und Tischen und die Grenze eines Raums wirken nur, wo Swayy Tische vergibt.
    $roomEffects = in_array('lock', array_column($entry['effects'], 'type'), true) || ($entry['form']['limit_room_id'] ?? null) !== null;
    $bookings = $salon ? 'Termine' : 'Buchungen';
@endphp
<li class="flex flex-wrap items-start gap-x-4 gap-y-3 px-4 py-4">
    <div aria-hidden="true" class="flex w-20 shrink-0 flex-col items-center rounded-lg border border-stone-200 bg-stone-50 px-1 py-1.5 text-center leading-tight">
        <span class="text-xs font-semibold uppercase tracking-wide text-stone-600">{{ $sheet['month'] }}</span>
        <span class="text-xl font-bold tabular-nums text-stone-900"><time datetime="{{ $entry['from'] }}">{{ $sheet['start'] }}</time>@if($sheet['end'] !== null)–<time datetime="{{ $entry['until'] }}">{{ $sheet['end'] }}</time>@endif</span>
    </div>
    <div class="min-w-0 flex-1 basis-56 space-y-1.5">
        @if($entry['label'])
            <p class="font-semibold [overflow-wrap:anywhere]">{{ $entry['label'] }}</p>
        @endif
        <ul class="space-y-1">
            @foreach($entry['effects'] as $index => $effect)
                <li class="flex items-start gap-2 text-sm [overflow-wrap:anywhere]{{ ! $entry['label'] && $index === 0 ? ' font-semibold' : '' }}">
                    <x-status-icon :state="$states[$effect['type']] ?? 'off'" :label="$stateLabels[$effect['type']] ?? $effect['text']" :announce="false" class="mt-0.5" />
                    <span>{{ $effect['text'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="text-[0.8125rem] text-stone-600">{{ $period }}</p>
        @if($roomEffects && ! $assignsTables)
            <p class="text-[0.8125rem] text-stone-600">Räume und Tische wirken auf Buchungen in den Zählweisen {{ CapacityModes::assigningList() }}. Aktuell gilt „{{ CapacityModes::label($capacityMode) }}“ (Kapazität &amp; Dauer).</p>
        @endif
        @if($entry['note'] !== null)
            <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $entry['note'] }}</p>
        @endif
    </div>
    <div class="flex min-w-0 flex-wrap items-center gap-1">
        @if($canEdit)
            <button type="button" data-drawer-open="ausnahme-{{ $entry['key'] }}" class="btn btn-quiet">Bearbeiten<span class="sr-only"> „{{ $name }}“</span></button>
        @endif
        <x-inline-confirm :id="'ausnahme-loeschen-'.$entry['key']" :action="route('admin.settings.exceptions.destroy', $entry['key'])" method="DELETE"
                          label="Löschen" confirm="Ausnahme löschen" :subject="$name" :reload="true"
                          :question="'Ausnahme „'.$name.'“ löschen? Swayy nimmt danach wieder '.$bookings.' nach den regulären Zeiten an.'" />
    </div>
</li>

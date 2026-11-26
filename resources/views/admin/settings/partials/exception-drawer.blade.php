@use('App\Support\ScheduleExceptionPeriod')
{{-- Seitenleiste einer Ausnahme: $entry null zum Anlegen, sonst ein Eintrag aus ScheduleExceptions zum Bearbeiten.
     Die Seite trägt nur die zum Anlegen. Die zum Bearbeiten liefert ExceptionsController::drawer erst beim Öffnen
     (drawer.js lädt sie über data-drawer-src), damit die Seite nicht je Eintrag ein ganzes Formular mitbringt.
     Die übrigen Werte ($blank, $roomsFor, $presetTable, $salon, $assignsTables, $capacityMode, $limits, $previewDelay)
     kommen aus ExceptionsController::formContext. --}}
@php
    $drawerId = $entry === null ? 'ausnahme-neu' : 'ausnahme-'.$entry['key'];
    $values = $entry['form'] ?? $blank;
    $texts = [
        'start' => 'Wähle Tage und was anders ist; hier steht dann, was '.($salon ? 'Kunden' : 'Gäste').' erleben.',
        'incomplete' => 'Noch unvollständig: :message',
        'unavailable' => 'Die Vorschau ist gerade nicht verfügbar. Speichern klappt trotzdem.',
    ];
@endphp
<x-drawer :id="$drawerId" :title="$entry === null ? 'Neue Ausnahme' : 'Ausnahme bearbeiten'"
          :description="$entry === null ? 'Gilt für einen Tag oder einen Zeitraum.' : ($entry['label'] ?: ScheduleExceptionPeriod::text($entry['from'], $entry['until']))">
    @include('admin.settings.partials.exception-form', [
        'prefix' => $drawerId,
        'action' => $entry === null ? route('admin.settings.exceptions.store') : route('admin.settings.exceptions.update', $entry['key']),
        'method' => $entry === null ? 'POST' : 'PUT',
        'key' => $entry['key'] ?? null,
        'values' => $values,
        'rooms' => $roomsFor($values),
        'preset' => $entry === null ? $presetTable : null,
    ])
    <x-slot:footer>
        <div class="w-full space-y-1">
            <p id="{{ $drawerId }}-sentence-title" class="text-sm font-semibold text-stone-900">So wirkt es</p>
            <p data-effect-sentence role="status" aria-live="polite" aria-atomic="true" aria-labelledby="{{ $drawerId }}-sentence-title"
               data-text-start="{{ $texts['start'] }}" data-text-incomplete="{{ $texts['incomplete'] }}" data-text-unavailable="{{ $texts['unavailable'] }}"
               class="text-sm text-stone-700 [overflow-wrap:anywhere]">{{ $texts['start'] }}</p>
        </div>
        <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
        <button type="submit" form="form-{{ $drawerId }}" class="btn btn-primary">{{ $entry === null ? 'Ausnahme anlegen' : 'Speichern' }}</button>
    </x-slot:footer>
</x-drawer>

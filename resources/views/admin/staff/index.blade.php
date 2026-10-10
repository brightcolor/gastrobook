@extends('layouts.admin')
@section('title', 'Mitarbeiter')

@section('content')
@php
    $tz = $location->timezone;
    // Die Spalte fasst auch Werte, die das Farbfeld nicht kennt; nur #rrggbb gelangt in den Stil.
    $dot = fn ($member): ?string => preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $member->color) === 1 ? $member->color : null;
    // Leistungen, Arbeitszeit, nächste Abwesenheit; eine pausierte Person nennt das dazu.
    $details = function ($member) use ($tz): string {
        $hours = $member->workingHours->count();
        $next = $member->absences->first();
        $absence = null;
        if ($next !== null) {
            $absence = $next->starts_at->lte(now())
                ? __('gerade abwesend bis :date', ['date' => $next->ends_at->copy()->setTimezone($tz)->format('d.m.')])
                : __('abwesend ab :date', ['date' => $next->starts_at->copy()->setTimezone($tz)->format('d.m.')]);
        }

        return collect([
            $member->services->isNotEmpty() ? $member->services->pluck('name')->join(', ') : __('Noch keine Leistung'),
            $hours > 0 ? __(':count Zeitfenster je Woche', ['count' => $hours]) : __('Arbeitszeit wie Öffnungszeiten'),
            $absence,
            $member->is_active ? null : __('Pausiert'),
        ])->filter()->implode(' · ');
    };
    $span = fn ($absence): string => $absence->starts_at->copy()->setTimezone($tz)->format('d.m.Y H:i').' – '.$absence->ends_at->copy()->setTimezone($tz)->format('d.m.Y H:i');
@endphp
<x-page-header title="Mitarbeiter" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Wer Termine annimmt: mit Leistungen, Arbeitszeiten und Abwesenheiten.">
    <x-slot:actions>
        <button type="button" data-drawer-open="person-neu" class="btn btn-primary">Person hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($members->isEmpty())
    <x-empty-state title="Noch niemand angelegt">
        Gäste buchen Termine bei einer Person. Lege an, wer bei dir Termine annimmt.
        <x-slot:action>
            <button type="button" data-drawer-open="person-neu" class="btn btn-primary">Person hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($members as $member)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                {{-- Das Wort „Pausiert“ steht sichtbar in der Zeile und dient als Vorlesetext; das Symbol trägt dann keinen eigenen. --}}
                <x-status-icon :state="$member->is_active ? 'ok' : 'off'" :label="$member->is_active ? 'Buchbar' : 'Pausiert'" :announce="$member->is_active" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="person-{{ $member->id }}"
                            class="flex items-center gap-2 text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                        @if($dot($member))
                            <span class="h-3 w-3 shrink-0 rounded-full ring-1 ring-stone-300" style="background-color: {{ $dot($member) }}" aria-hidden="true"></span>
                        @endif
                        {{ $member->name }}
                    </button>
                    <p class="text-[0.8125rem] text-stone-600">{{ $details($member) }}</p>
                </div>
                <x-inline-confirm :id="'person-loeschen-'.$member->id" :action="route('admin.staff.destroy', $member)" method="DELETE"
                                  label="Löschen" confirm="Person löschen" :subject="$member->name" :reload="true"
                                  :question="'„'.$member->name.'“ löschen? Arbeitszeiten und Abwesenheiten gehen mit.'" />
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="person-neu" title="Neue Person" description="Arbeitszeiten und Abwesenheiten trägst du danach in ihrer Seitenleiste ein.">
        <form id="form-person-neu" method="POST" action="{{ route('admin.staff.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.staff.partials.fields', ['member' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-person-neu" class="btn btn-primary">Person anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($members as $member)
        @php
            $hours = $member->workingHours
                ->sortBy([['weekday', 'asc'], ['starts_at', 'asc']])
                ->values()
                ->map(fn ($hour) => [
                    'weekday' => (int) $hour->weekday,
                    'starts_at' => substr((string) $hour->starts_at, 0, 5),
                    'ends_at' => substr((string) $hour->ends_at, 0, 5),
                ]);
        @endphp
        <x-drawer :id="'person-'.$member->id" :title="$member->name" description="Profil, Arbeitszeiten und Abwesenheiten.">
            <form id="form-person-{{ $member->id }}" method="POST" action="{{ route('admin.staff.update', $member) }}" data-ajax data-reload-after-save data-drawer-section="Profil" class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.staff.partials.fields', ['member' => $member, 'prefix' => (string) $member->id])
            </form>

            <section aria-labelledby="arbeitszeit-{{ $member->id }}-titel" class="space-y-4 border-t border-stone-200 pt-5">
                <h3 id="arbeitszeit-{{ $member->id }}-titel" class="text-sm font-semibold">Arbeitszeiten</h3>
                <p class="text-[0.8125rem] text-stone-600">Ohne Eintrag gelten die Öffnungszeiten des Standorts. Mit Einträgen ist {{ $member->name }} in diesen Zeitfenstern buchbar.</p>
                <form method="POST" action="{{ route('admin.staff.working-hours', $member) }}" data-ajax data-reload-after-save data-drawer-section="Arbeitszeiten" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div data-rows data-rows-max="{{ $limits['staff_hours_max'] }}" class="space-y-3">
                        <div data-rows-list class="space-y-3">
                            @foreach($hours as $key => $hour)
                                @include('admin.staff.partials.hour-row', ['key' => $key, 'hour' => $hour])
                            @endforeach
                        </div>
                        <template data-rows-template>
                            @include('admin.staff.partials.hour-row', ['key' => '__INDEX__', 'hour' => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '18:00']])
                        </template>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-rows-add class="btn btn-secondary">Zeitfenster hinzufügen</button>
                            <button type="submit" class="btn btn-primary">Arbeitszeiten speichern</button>
                        </div>
                    </div>
                </form>
            </section>

            <section aria-labelledby="abwesenheit-{{ $member->id }}-titel" class="space-y-4 border-t border-stone-200 pt-5">
                <h3 id="abwesenheit-{{ $member->id }}-titel" class="text-sm font-semibold">Abwesenheiten</h3>
                @if($member->absences->isEmpty())
                    <p class="text-sm text-stone-600">Für die kommende Zeit ist nichts eingetragen.</p>
                @else
                    <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                        @foreach($member->absences as $absence)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="[overflow-wrap:anywhere]">{{ $span($absence) }}@if($absence->reason) · {{ $absence->reason }}@endif</span>
                                <x-inline-confirm :id="'abwesenheit-loeschen-'.$absence->id" :action="route('admin.staff.absences.destroy', $absence)" method="DELETE"
                                                  label="Entfernen" confirm="Entfernen" :subject="$span($absence)" :reload="true"
                                                  :question="'Abwesenheit '.$span($absence).' entfernen? '.$member->name.' ist dann wieder buchbar.'" />
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('admin.staff.absences.store', $member) }}" data-ajax data-reset-after-save data-reload-after-save data-drawer-section="Abwesenheit" class="space-y-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-field name="starts_on" :id="'abw-'.$member->id.'-von'" label="Von" type="date" required />
                        <x-field name="starts_time" :id="'abw-'.$member->id.'-von-zeit'" label="ab Uhrzeit" type="time" help="Leer: ab Tagesbeginn." />
                        <x-field name="ends_on" :id="'abw-'.$member->id.'-bis'" label="Bis" type="date" required />
                        <x-field name="ends_time" :id="'abw-'.$member->id.'-bis-zeit'" label="bis Uhrzeit" type="time" help="Leer: bis Tagesende." />
                    </div>
                    <x-field name="reason" :id="'abw-'.$member->id.'-grund'" label="Grund" :maxlength="$limits['absence_reason_max']" placeholder="etwa Urlaub" autocomplete="off" />
                    <button type="submit" class="btn btn-secondary">Abwesenheit eintragen</button>
                </form>
            </section>

            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-person-{{ $member->id }}" class="btn btn-primary">Profil speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection

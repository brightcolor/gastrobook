@extends('layouts.admin')
@section('title', 'Team & Rollen')

@section('content')
<x-page-header title="Team & Rollen" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Wer sich anmelden darf und was die Person tun kann. Jede Person bekommt einen eigenen Zugang; so steht im Protokoll, wer etwas geändert hat.">
    <x-slot:actions>
        <button type="button" data-drawer-open="einladen" class="btn btn-primary">Person einladen</button>
    </x-slot:actions>
</x-page-header>

<section aria-labelledby="team-title" class="space-y-3">
    <h2 id="team-title" class="text-base font-semibold">Team</h2>
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($memberships as $m)
            @php
                $isSelf = $m->user_id === auth()->id();
                $canEdit = $canManageRoles && ! $isSelf && ($manageable[$m->id] ?? false);
                $access = $m->all_locations
                    ? 'Alle Standorte'
                    : ($m->user?->allowedLocations->pluck('name')->implode(', ') ?: 'Kein Standort freigegeben');
            @endphp
            <li class="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1fr)_13rem_auto] md:items-center">
                <div class="min-w-0">
                    <p class="font-semibold [overflow-wrap:anywhere]">
                        {{ $m->user?->name }}
                        @if($isSelf)
                            <span class="text-[0.8125rem] font-normal text-stone-600">(du)</span>
                        @endif
                    </p>
                    <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $m->user?->email }}</p>
                    @if($hasSeveralLocations)
                        <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $access }}</p>
                    @endif
                </div>
                <div>
                    @if($canEdit && array_key_exists($m->role, $roleOptions))
                        {{-- Gespeichert wird erst mit dem Knopf: Pfeiltasten wechseln unter Windows die Auswahl schon beim Durchblättern. --}}
                        <form method="POST" action="{{ route('admin.users.role', $m) }}" data-ajax data-reset-on-error
                              class="flex flex-wrap items-center gap-2 [&>[data-form-problems]]:basis-full">
                            @csrf
                            @method('PUT')
                            <label for="role-{{ $m->id }}" class="sr-only">Rolle von {{ $m->user?->name }}</label>
                            <select id="role-{{ $m->id }}" name="role" class="min-w-0 flex-1">
                                @foreach($roleOptions as $role => $label)
                                    <option value="{{ $role }}" @selected($m->role === $role)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-secondary">Speichern<span class="sr-only"> Rolle von „{{ $m->user?->name }}“</span></button>
                        </form>
                    @else
                        <p class="text-sm">{{ __('roles.'.$m->role.'.label') }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-start gap-1 md:justify-end">
                    @if($canEdit && $hasSeveralLocations)
                        <button type="button" data-drawer-open="zugang-{{ $m->id }}" class="btn btn-quiet">Standorte<span class="sr-only"> von „{{ $m->user?->name }}“</span></button>
                    @endif
                    @if($canEdit)
                        <x-inline-confirm id="entfernen-{{ $m->id }}" :action="route('admin.users.remove', $m)" method="DELETE"
                                          label="Entfernen" confirm="Entfernen" :subject="$m->user?->name"
                                          :question="'„'.$m->user?->name.'“ aus dem Betrieb entfernen? Die Person kann sich hier dann nicht mehr anmelden; ihr Konto bleibt bestehen.'" />
                        <x-inline-confirm id="loeschen-{{ $m->id }}" :action="route('admin.users.delete', $m)" method="DELETE"
                                          label="Konto löschen" confirm="Konto löschen" :subject="$m->user?->name"
                                          :question="'Konto von „'.$m->user?->name.'“ löschen? Gehört die Person zu keinem weiteren Betrieb, ist ihr Zugang danach endgültig weg.'" />
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</section>

<section id="einladungen" aria-labelledby="einladungen-title" class="mt-8 scroll-mt-6 space-y-3">
    <h2 id="einladungen-title" class="text-base font-semibold">Offene Einladungen</h2>
    @if($invitations->isEmpty())
        <x-empty-state title="Keine offenen Einladungen">
            Wen du einlädst, erscheint hier mit dem Link zum Weitergeben, bis die Person ihn öffnet.
            <x-slot:action>
                <button type="button" data-drawer-open="einladen" class="btn btn-secondary">Person einladen</button>
            </x-slot:action>
        </x-empty-state>
    @else
        <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
            @foreach($invitations as $inv)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $inv->email }}</p>
                        <p class="text-[0.8125rem] text-stone-600">{{ __('roles.'.$inv->role.'.label') }} · gültig bis {{ $inv->expires_at->copy()->setTimezone($tz)->format('d.m.Y') }}</p>
                    </div>
                    @php($link = route('invitation.accept', $inv->token))
                    <div class="flex w-full min-w-0 items-center gap-2 sm:w-auto sm:flex-1">
                        <label for="invite-link-{{ $inv->id }}" class="sr-only">Einladungslink für {{ $inv->email }}</label>
                        <input id="invite-link-{{ $inv->id }}" type="text" readonly value="{{ $link }}" class="min-w-0 flex-1 text-[0.8125rem] text-stone-700">
                        <button type="button" class="btn btn-secondary shrink-0"
                                data-copy="{{ $link }}"
                                data-copy-message="Link für {{ $inv->email }} kopiert.">Link kopieren<span class="sr-only"> für {{ $inv->email }}</span></button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section id="rollen" aria-labelledby="rollen-title" class="mt-8 scroll-mt-6 space-y-3">
    <h2 id="rollen-title" class="text-base font-semibold">Was die Rollen dürfen</h2>
    <dl class="grid gap-x-6 gap-y-3 rounded-xl border border-stone-200 bg-white px-5 py-4 text-sm sm:grid-cols-[12rem_minmax(0,1fr)]">
        @foreach($allRoles as $role)
            <dt class="font-semibold">{{ __('roles.'.$role.'.label') }}</dt>
            <dd class="text-stone-600">{{ __('roles.'.$role.'.summary') }}</dd>
        @endforeach
    </dl>
</section>

@push('drawers')
    <x-drawer id="einladen" title="Person einladen"
              description="Hat die Person schon ein Konto bei Swayy, gehört sie sofort zum Team. Sonst entsteht ein Link, den du ihr schickst.">
        <form id="form-einladen" method="POST" action="{{ route('admin.users.invite') }}" data-ajax class="space-y-5">
            @csrf
            <x-field name="email" label="E-Mail-Adresse" type="email" id="invite-email" required maxlength="200" />
            <fieldset class="space-y-2">
                <legend class="mb-1 text-sm font-semibold text-stone-800">Rolle<span class="text-red-700" aria-hidden="true"> *</span></legend>
                @foreach($roleOptions as $role => $label)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-3 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand)">
                        <input type="radio" name="role" value="{{ $role }}" required class="mt-1">
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ $label }}</span>
                            <span class="block text-[0.8125rem] text-stone-600">{{ __('roles.'.$role.'.summary') }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            @if($hasSeveralLocations)
                @include('admin.users.partials.locations', ['allLocations' => true, 'selected' => []])
            @else
                <input type="hidden" name="all_locations" value="1">
            @endif
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-einladen" class="btn btn-primary">Einladen</button>
        </x-slot:footer>
    </x-drawer>

    @if($canManageRoles && $hasSeveralLocations)
        @foreach($memberships as $m)
            @continue($m->user_id === auth()->id() || ! ($manageable[$m->id] ?? false))
            <x-drawer id="zugang-{{ $m->id }}" :title="'Standorte für '.$m->user?->name">
                <form id="form-zugang-{{ $m->id }}" method="POST" action="{{ route('admin.users.locations', $m) }}" data-ajax class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.users.partials.locations', [
                        'allLocations' => (bool) $m->all_locations,
                        'selected' => $m->user?->allowedLocations->pluck('id')->all() ?? [],
                    ])
                </form>
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-zugang-{{ $m->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endforeach
    @endif
@endpush
@endsection

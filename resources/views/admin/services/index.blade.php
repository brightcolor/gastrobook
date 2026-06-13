@extends('layouts.admin')
@section('title', 'Leistungen')
@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="text-2xl font-bold">Leistungen – {{ $location->name }}</h1>
</div>

{{-- Neue Leistung anlegen --}}
<div class="mb-6 rounded-2xl bg-white p-5 shadow-sm">
    <h2 class="mb-4 font-bold">Neue Leistung</h2>
    <form method="POST" action="{{ route('admin.services.store') }}" class="space-y-3">
        @csrf
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                <input type="text" name="name" required maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="z.B. Haarschnitt Herren">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Dauer (Min.) *</label>
                <input type="number" name="duration_minutes" required min="5" max="480"
                       value="{{ old('duration_minutes', 30) }}"
                       class="w-full rounded-lg border-stone-200 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Preis (Cent, 0 = auf Anfrage)</label>
                <input type="number" name="price_minor" required min="0" max="100000"
                       value="{{ old('price_minor', 0) }}"
                       class="w-full rounded-lg border-stone-200 text-sm">
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Beschreibung</label>
                <input type="text" name="description" maxlength="1000" value="{{ old('description') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="Kurze Beschreibung (optional)">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Mitarbeiter (kann diese Leistung)</label>
                <div class="flex flex-wrap gap-2">
                    @foreach($staff as $member)
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" name="staff_ids[]" value="{{ $member->id }}"
                                   @checked(in_array($member->id, old('staff_ids', [])))>
                            {{ $member->name }}
                        </label>
                    @endforeach
                    @if($staff->isEmpty())
                        <span class="text-xs text-stone-400">Zuerst Mitarbeiter anlegen.</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                Aktiv (online buchbar)
            </label>
            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                Leistung anlegen
            </button>
        </div>
    </form>
</div>

{{-- Leistungsliste --}}
@if($services->isEmpty())
    <p class="rounded-2xl bg-white p-6 text-center text-sm text-stone-500 shadow-sm">Noch keine Leistungen angelegt.</p>
@else
    <div class="space-y-3">
        @foreach($services as $service)
            <details class="rounded-2xl bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center gap-4 p-4 hover:bg-stone-50">
                    @if($service->color)
                        <span class="inline-block h-4 w-4 rounded-full" style="background:{{ $service->color }}"></span>
                    @endif
                    <span class="font-semibold">{{ $service->name }}</span>
                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs">{{ $service->durationFormatted() }}</span>
                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs">{{ $service->priceFormatted() }}</span>
                    @if(!$service->is_active)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">inaktiv</span>
                    @endif
                    @if($service->staff->isNotEmpty())
                        <span class="text-xs text-stone-400">{{ $service->staff->pluck('name')->join(', ') }}</span>
                    @endif
                    <span class="ml-auto text-xs text-stone-400">bearbeiten ▾</span>
                </summary>
                <div class="border-t border-stone-100 p-5">
                    <form method="POST" action="{{ route('admin.services.update', $service) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                                <input type="text" name="name" required maxlength="120" value="{{ $service->name }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Dauer (Min.) *</label>
                                <input type="number" name="duration_minutes" required min="5" max="480"
                                       value="{{ $service->duration_minutes }}" class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Preis (Cent)</label>
                                <input type="number" name="price_minor" required min="0" max="100000"
                                       value="{{ $service->price_minor }}" class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Beschreibung</label>
                                <input type="text" name="description" maxlength="1000" value="{{ $service->description }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe (Hex)</label>
                                <div class="flex gap-2">
                                    <input type="color" name="color" value="{{ $service->color ?? '#6b7280' }}"
                                           class="h-9 w-12 rounded border-stone-200">
                                    <input type="text" id="colorText_{{ $service->id }}"
                                           value="{{ $service->color ?? '' }}" placeholder="#6b7280"
                                           class="flex-1 rounded-lg border-stone-200 text-sm font-mono">
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-stone-500">Mitarbeiter</label>
                            <div class="flex flex-wrap gap-3">
                                @foreach($staff as $member)
                                    <label class="flex items-center gap-1 text-sm">
                                        <input type="checkbox" name="staff_ids[]" value="{{ $member->id }}"
                                               @checked($service->staff->contains($member))>
                                        {{ $member->name }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="is_active" value="1" @checked($service->is_active)>
                                Aktiv
                            </label>
                            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                                Speichern
                            </button>
                            <form method="POST" action="{{ route('admin.services.destroy', $service) }}"
                                  onsubmit="return confirm('Leistung wirklich löschen?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                    Löschen
                                </button>
                            </form>
                        </div>
                    </form>
                </div>
            </details>
        @endforeach
    </div>
@endif
@endsection

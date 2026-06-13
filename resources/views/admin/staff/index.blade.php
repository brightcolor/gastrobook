@extends('layouts.admin')
@section('title', 'Mitarbeiter')
@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="text-2xl font-bold">Mitarbeiter – {{ $location->name }}</h1>
</div>

{{-- Neuen Mitarbeiter anlegen --}}
<div class="mb-6 rounded-2xl bg-white p-5 shadow-sm">
    <h2 class="mb-4 font-bold">Mitarbeiter:in anlegen</h2>
    <form method="POST" action="{{ route('admin.staff.store') }}" class="space-y-3">
        @csrf
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                <input type="text" name="name" required maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="z.B. Anna Müller">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Kurzbiografie (optional)</label>
                <input type="text" name="bio" maxlength="500" value="{{ old('bio') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="z.B. Spezialistin für Colorationen">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe (Kalender)</label>
                <input type="color" name="color" value="{{ old('color', '#0d9488') }}"
                       class="h-9 w-12 rounded border-stone-200">
            </div>
        </div>
        @if($services->isNotEmpty())
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Angebotene Leistungen</label>
                <div class="flex flex-wrap gap-3">
                    @foreach($services as $svc)
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" name="service_ids[]" value="{{ $svc->id }}"
                                   @checked(in_array($svc->id, old('service_ids', [])))>
                            {{ $svc->name }} ({{ $svc->durationFormatted() }})
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                Aktiv (buchbar)
            </label>
            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                Anlegen
            </button>
        </div>
    </form>
</div>

{{-- Mitarbeiterliste --}}
@if($members->isEmpty())
    <p class="rounded-2xl bg-white p-6 text-center text-sm text-stone-500 shadow-sm">Noch keine Mitarbeiter angelegt.</p>
@else
    <div class="space-y-3">
        @foreach($members as $member)
            <details class="rounded-2xl bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center gap-3 p-4 hover:bg-stone-50">
                    @if($member->color)
                        <span class="inline-block h-5 w-5 rounded-full" style="background:{{ $member->color }}"></span>
                    @endif
                    <span class="font-semibold">{{ $member->name }}</span>
                    @if($member->bio)
                        <span class="text-xs text-stone-400">{{ $member->bio }}</span>
                    @endif
                    @if($member->services->isNotEmpty())
                        <span class="rounded-full bg-teal-50 px-2 py-0.5 text-xs text-teal-700">
                            {{ $member->services->pluck('name')->join(', ') }}
                        </span>
                    @endif
                    @if(!$member->is_active)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">inaktiv</span>
                    @endif
                    <span class="ml-auto text-xs text-stone-400">bearbeiten ▾</span>
                </summary>
                <div class="border-t border-stone-100 p-5">
                    <form method="POST" action="{{ route('admin.staff.update', $member) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                                <input type="text" name="name" required maxlength="120" value="{{ $member->name }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Kurzbiografie</label>
                                <input type="text" name="bio" maxlength="500" value="{{ $member->bio }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe</label>
                                <input type="color" name="color" value="{{ $member->color ?? '#0d9488' }}"
                                       class="h-9 w-12 rounded border-stone-200">
                            </div>
                        </div>
                        @if($services->isNotEmpty())
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Angebotene Leistungen</label>
                                <div class="flex flex-wrap gap-3">
                                    @foreach($services as $svc)
                                        <label class="flex items-center gap-1 text-sm">
                                            <input type="checkbox" name="service_ids[]" value="{{ $svc->id }}"
                                                   @checked($member->services->contains($svc))>
                                            {{ $svc->name }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="is_active" value="1" @checked($member->is_active)>
                                Aktiv
                            </label>
                            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                                Speichern
                            </button>
                            <form method="POST" action="{{ route('admin.staff.destroy', $member) }}"
                                  onsubmit="return confirm('Mitarbeiter:in wirklich löschen?')">
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

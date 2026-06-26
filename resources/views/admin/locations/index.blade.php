@extends('layouts.admin')
@section('title', 'Standorte')
@section('content')

<h1 class="mb-1 text-2xl font-bold">Standorte</h1>
<p class="mb-5 text-sm text-stone-500">
    {{ $used }} von {{ $limit === null ? '∞' : $limit }} Standorten genutzt
    @if($limit !== null && $used >= $limit)
        · <span class="font-semibold text-amber-600">Limit erreicht</span>
    @endif
</p>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        @forelse($locations as $loc)
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                <details>
                    <summary class="flex cursor-pointer items-center justify-between gap-3">
                        <span class="font-bold">
                            {{ $loc->name }}
                            @if(! $loc->is_active)
                                <span class="ml-2 rounded bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-600">Inaktiv</span>
                            @endif
                        </span>
                        <span class="text-xs text-stone-400">{{ $loc->rooms_count }} Räume · {{ $loc->tables_count }} Tische</span>
                    </summary>

                    <form method="POST" action="{{ route('admin.locations.update', $loc) }}" class="mt-4 space-y-3 text-sm">
                        @csrf @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">Name *
                                <input name="name" required value="{{ $loc->name }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                            <label class="block">Zeitzone *
                                <select name="timezone" class="mt-1 w-full rounded-lg border-stone-200">
                                    @foreach($timezones as $tz)
                                        <option value="{{ $tz }}" @selected($loc->timezone === $tz)>{{ $tz }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">Telefon
                                <input name="phone" value="{{ $loc->phone }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                            <label class="block">E-Mail
                                <input type="email" name="email" value="{{ $loc->email }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                            <label class="block sm:col-span-2">Straße & Nr.
                                <input name="address_line1" value="{{ $loc->address_line1 }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                            <label class="block">PLZ
                                <input name="postal_code" value="{{ $loc->postal_code }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                            <label class="block">Stadt
                                <input name="city" value="{{ $loc->city }}" class="mt-1 w-full rounded-lg border-stone-200">
                            </label>
                        </div>
                        <div class="flex items-center justify-between pt-1">
                            <button class="rounded-xl bg-stone-900 px-4 py-2 font-bold text-white">Speichern</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.locations.toggle-active', $loc) }}" class="mt-2"
                          onsubmit="return confirm('{{ $loc->is_active ? 'Standort deaktivieren? Er verschwindet aus der Online-Buchung.' : 'Standort wieder aktivieren?' }}')">
                        @csrf
                        <button class="text-xs {{ $loc->is_active ? 'text-amber-600 hover:text-amber-700' : 'text-teal-600 hover:text-teal-700' }}">
                            {{ $loc->is_active ? 'Deaktivieren' : 'Aktivieren' }}
                        </button>
                    </form>
                    @error('active')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </details>
            </div>
        @empty
            <p class="text-sm text-stone-500">Noch keine Standorte.</p>
        @endforelse
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="font-bold">Standort hinzufügen</h2>
        @if($canAdd)
            <form method="POST" action="{{ route('admin.locations.store') }}" class="mt-3 space-y-3 text-sm">
                @csrf
                <input name="name" required placeholder="Name *" value="{{ old('name') }}" class="w-full rounded-lg border-stone-200">
                <select name="timezone" class="w-full rounded-lg border-stone-200">
                    @foreach($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', 'Europe/Berlin') === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
                <input name="phone" placeholder="Telefon" value="{{ old('phone') }}" class="w-full rounded-lg border-stone-200">
                <input type="email" name="email" placeholder="E-Mail" value="{{ old('email') }}" class="w-full rounded-lg border-stone-200">
                <input name="address_line1" placeholder="Straße & Nr." value="{{ old('address_line1') }}" class="w-full rounded-lg border-stone-200">
                <div class="flex gap-2">
                    <input name="postal_code" placeholder="PLZ" value="{{ old('postal_code') }}" class="w-1/3 rounded-lg border-stone-200">
                    <input name="city" placeholder="Stadt" value="{{ old('city') }}" class="w-2/3 rounded-lg border-stone-200">
                </div>
                @error('name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Standort anlegen</button>
            </form>
            <p class="mt-3 text-xs text-stone-500">Öffnungszeiten, Räume und Tische legst du danach über den Standort-Umschalter oben links + Einstellungen an.</p>
        @else
            <p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                Dein Tarif erlaubt {{ $limit }} Standort{{ $limit === 1 ? '' : 'e' }}. Für weitere Standorte ist ein Upgrade nötig.
            </p>
        @endif
    </div>
</div>
@endsection

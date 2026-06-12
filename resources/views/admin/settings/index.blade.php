@extends('layouts.admin')
@section('title', 'Einstellungen')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Einstellungen – {{ $location->name }}</h1>

<div class="mb-4 rounded-2xl bg-white p-4 text-sm shadow-sm">
    Öffentliche Buchungsseite:
    <a href="{{ route('booking.show', [$location->tenant->slug, $location->slug]) }}" target="_blank"
       class="font-mono text-teal-700 underline">{{ route('booking.show', [$location->tenant->slug, $location->slug]) }}</a>
</div>

<div class="grid gap-6 xl:grid-cols-2">
    {{-- Booking rules --}}
    <form method="POST" action="{{ route('admin.settings.booking-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm">
        @csrf @method('PUT')
        <h2 class="mb-3 font-bold">Buchungsregeln</h2>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Intervall (Min.)</label>
                <select name="slot_interval_minutes" class="w-full rounded-lg border-stone-200">
                    @foreach([15, 30, 60] as $i)<option value="{{ $i }}" @selected($settings->slot_interval_minutes == $i)>{{ $i }}</option>@endforeach
                </select></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Standarddauer (Min.)</label>
                <input type="number" name="default_duration_minutes" value="{{ $settings->default_duration_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Puffer zw. Reservierungen (Min.)</label>
                <input type="number" name="buffer_minutes" value="{{ $settings->buffer_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Mindestvorlauf (Min.)</label>
                <input type="number" name="min_lead_minutes" value="{{ $settings->min_lead_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Vorausbuchung (Tage)</label>
                <input type="number" name="max_advance_days" value="{{ $settings->max_advance_days }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Stornofrist (Min.)</label>
                <input type="number" name="cancellation_deadline_minutes" value="{{ $settings->cancellation_deadline_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Min. Personen online</label>
                <input type="number" name="min_party_online" value="{{ $settings->min_party_online }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Personen online</label>
                <input type="number" name="max_party_online" value="{{ $settings->max_party_online }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Kapazitätsmodus</label>
                <select name="capacity_mode" class="w-full rounded-lg border-stone-200">
                    <option value="table" @selected($settings->capacity_mode === 'table')>Tischbasiert</option>
                    <option value="person" @selected($settings->capacity_mode === 'person')>Personenbasiert</option>
                    <option value="hybrid" @selected($settings->capacity_mode === 'hybrid')>Hybrid</option>
                </select></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Gäste pro Slot (Person/Hybrid)</label>
                <input type="number" name="max_covers_per_slot" value="{{ $settings->max_covers_per_slot }}" class="w-full rounded-lg border-stone-200"></div>
        </div>
        <div class="mt-3 space-y-1.5 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_confirm" value="1" @checked($settings->auto_confirm)> Online-Reservierungen automatisch bestätigen</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="request_only" value="1" @checked($settings->request_only)> Nur als Anfrage annehmen</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="waitlist_enabled" value="1" @checked($settings->waitlist_enabled)> Warteliste aktiv</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="walkins_enabled" value="1" @checked($settings->walkins_enabled)> Walk-ins aktiv</label>
        </div>
        <button class="mt-4 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>

    {{-- Opening hours --}}
    <form method="POST" action="{{ route('admin.settings.opening-hours') }}" class="rounded-2xl bg-white p-5 shadow-sm">
        @csrf @method('PUT')
        <h2 class="mb-3 font-bold">Öffnungszeiten</h2>
        <div id="hoursContainer" class="space-y-2 text-sm">
            @php($weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'])
            @foreach($openingHours as $i => $hour)
                <div class="hour-row flex items-center gap-2">
                    <select name="hours[{{ $i }}][weekday]" class="rounded-lg border-stone-200">
                        @foreach($weekdays as $wd => $name)<option value="{{ $wd }}" @selected($hour->weekday == $wd)>{{ $name }}</option>@endforeach
                    </select>
                    <input type="time" name="hours[{{ $i }}][opens_at]" value="{{ substr($hour->opens_at, 0, 5) }}" class="rounded-lg border-stone-200">
                    <span>–</span>
                    <input type="time" name="hours[{{ $i }}][closes_at]" value="{{ substr($hour->closes_at, 0, 5) }}" class="rounded-lg border-stone-200">
                    <input type="text" name="hours[{{ $i }}][service_name]" value="{{ $hour->service_name }}" placeholder="Service" class="w-24 rounded-lg border-stone-200">
                    <button type="button" onclick="this.closest('.hour-row').remove()" class="text-red-500">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" id="addHour" class="mt-2 text-sm text-teal-700 underline">+ Zeitfenster hinzufügen</button>
        <div><button class="mt-4 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Öffnungszeiten speichern</button></div>
    </form>

    {{-- Rooms & tables --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 font-bold">Räume & Tische</h2>
        <form method="POST" action="{{ route('admin.settings.rooms.store') }}" class="mb-4 flex items-end gap-2 text-sm">
            @csrf
            <div class="grow"><label class="mb-1 block text-xs font-semibold text-stone-500">Neuer Raum</label>
                <input type="text" name="name" required placeholder="z. B. Wintergarten" class="w-full rounded-lg border-stone-200"></div>
            <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="is_outdoor" value="1"> Außen</label>
            <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
        </form>

        <form method="POST" action="{{ route('admin.settings.tables.store') }}" class="mb-4 grid grid-cols-2 gap-2 rounded-xl bg-stone-50 p-3 text-sm sm:grid-cols-6">
            @csrf
            <select name="room_id" required class="rounded-lg border-stone-200 sm:col-span-2">
                @foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->name }}</option>@endforeach
            </select>
            <input type="text" name="name" required placeholder="Tisch-Nr." class="rounded-lg border-stone-200">
            <input type="number" name="min_capacity" required min="1" placeholder="Min" class="rounded-lg border-stone-200">
            <input type="number" name="max_capacity" required min="1" placeholder="Max" class="rounded-lg border-stone-200">
            <button class="rounded-lg bg-stone-900 px-3 py-2 font-semibold text-white">+ Tisch</button>
        </form>

        <div class="max-h-80 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-stone-500"><tr><th class="py-1.5">Tisch</th><th>Raum</th><th>Kapazität</th><th>Eigenschaften</th><th></th></tr></thead>
                <tbody class="divide-y divide-stone-50">
                    @foreach($tables as $table)
                        <tr>
                            <td class="py-1.5 font-semibold">{{ $table->name }}</td>
                            <td>{{ $table->room?->name }}</td>
                            <td>{{ $table->min_capacity }}–{{ $table->max_capacity }}</td>
                            <td class="text-xs text-stone-500">
                                @if($table->outdoor)🌳 @endif @if($table->accessible)♿ @endif @if($table->joinable)🔗 @endif @if(!$table->online_bookable)🚫 online @endif
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('admin.settings.tables.delete', $table) }}" onsubmit="return confirm('Tisch löschen?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-500">✕</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Booking form fields --}}
    @if(auth()->user()->canInTenant('tenant.settings.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
    <form method="POST" action="{{ route('admin.settings.field-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm">
        @csrf @method('PUT')
        <h2 class="mb-1 font-bold">Formularfelder im Buchungswidget</h2>
        <p class="mb-3 text-xs text-stone-500">Steuert pro Feld, ob es Gästen angezeigt wird und ob es Pflicht ist. Der Name ist immer Pflicht.</p>
        <div class="space-y-2 text-sm">
            @foreach([
                'email' => 'E-Mail',
                'phone' => 'Telefon',
                'occasion' => 'Anlass',
                'allergies' => 'Allergien / Unverträglichkeiten',
                'note' => 'Anmerkung',
            ] as $field => $label)
                <div class="flex items-center justify-between gap-3">
                    <span>{{ $label }}</span>
                    <select name="fields[{{ $field }}]" class="rounded-lg border-stone-200 text-sm">
                        @foreach(['hidden' => 'Ausgeblendet', 'optional' => 'Optional', 'required' => 'Pflichtfeld'] as $value => $optionLabel)
                            <option value="{{ $value }}" @selected($settings->fieldRule($field) === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-amber-700">Hinweis: Werden E-Mail <em>und</em> Telefon ausgeblendet, können Gäste keine Bestätigung erhalten und es wird kein Gastprofil verknüpft.</p>
        <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>
    @endif

    {{-- MailWizz newsletter integration --}}
    @if(auth()->user()->canInTenant('integrations.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
    <form method="POST" action="{{ route('admin.settings.mailwizz') }}" class="rounded-2xl bg-white p-5 shadow-sm">
        @csrf @method('PUT')
        <div class="mb-1 flex items-center justify-between">
            <h2 class="font-bold">Newsletter: MailWizz</h2>
            @if($mailwizz)
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                    {{ ['connected' => 'bg-emerald-100 text-emerald-800', 'error' => 'bg-red-100 text-red-700'][$mailwizz->status] ?? 'bg-stone-100 text-stone-600' }}">
                    {{ ['connected' => 'verbunden', 'error' => 'Fehler', 'disconnected' => 'deaktiviert'][$mailwizz->status] ?? $mailwizz->status }}
                </span>
            @endif
        </div>
        <p class="mb-3 text-xs text-stone-500">
            Gäste mit Newsletter-Einwilligung werden automatisch in die MailWizz-Liste übertragen (mandantenweit).
            Double-Opt-In wird über die Listeneinstellung in MailWizz gesteuert.
        </p>
        <div class="space-y-2 text-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">API-URL (z. B. https://news.example.com/api)</label>
                <input type="url" name="api_url" required value="{{ $mailwizzCredentials['api_url'] ?? '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">API-Key {{ ($mailwizzCredentials['api_key'] ?? null) ? '(gesetzt – leer lassen zum Beibehalten)' : '' }}</label>
                <input type="password" name="api_key" autocomplete="new-password" placeholder="{{ ($mailwizzCredentials['api_key'] ?? null) ? '••••••••' : '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Listen-UID</label>
                <input type="text" name="list_uid" required value="{{ $mailwizzCredentials['list_uid'] ?? '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($mailwizz->status ?? '') === 'connected')> Synchronisierung aktiv</label>
        </div>
        <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern & Verbindung testen</button>
    </form>
    @endif

    {{-- Combinations + special hours --}}
    <div class="space-y-6">
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Tischkombinationen</h2>
            <form method="POST" action="{{ route('admin.settings.combinations.store') }}" class="space-y-2 text-sm">
                @csrf
                <input type="text" name="name" required placeholder="Name (z. B. T1+T2)" class="w-full rounded-lg border-stone-200">
                <select name="table_ids[]" multiple size="4" required class="w-full rounded-lg border-stone-200">
                    @foreach($tables->where('joinable', true) as $table)
                        <option value="{{ $table->id }}">{{ $table->name }} ({{ $table->room?->name }})</option>
                    @endforeach
                </select>
                <div class="grid grid-cols-2 gap-2">
                    <input type="number" name="min_capacity" required min="1" placeholder="Min. Personen" class="rounded-lg border-stone-200">
                    <input type="number" name="max_capacity" required min="1" placeholder="Max. Personen" class="rounded-lg border-stone-200">
                </div>
                <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
            </form>
            <div class="mt-3 space-y-1 text-sm">
                @foreach($combinations as $combo)
                    <div class="rounded-lg bg-stone-50 px-3 py-2">{{ $combo->name }}: {{ $combo->tables->pluck('name')->implode(' + ') }} ({{ $combo->min_capacity }}–{{ $combo->max_capacity }} P.)</div>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Sonderöffnungszeiten / Schließtage</h2>
            <form method="POST" action="{{ route('admin.settings.special-hours') }}" class="grid grid-cols-2 gap-2 text-sm">
                @csrf
                <input type="date" name="date" required class="rounded-lg border-stone-200">
                <input type="text" name="label" placeholder="z. B. Feiertag" class="rounded-lg border-stone-200">
                <input type="time" name="opens_at" class="rounded-lg border-stone-200">
                <input type="time" name="closes_at" class="rounded-lg border-stone-200">
                <label class="col-span-2 flex items-center gap-1.5"><input type="checkbox" name="closed" value="1"> Geschlossen</label>
                <button class="col-span-2 rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Speichern</button>
            </form>
            <div class="mt-3 space-y-1 text-sm">
                @foreach($specialHours as $sh)
                    <div class="rounded-lg bg-stone-50 px-3 py-2">
                        {{ $sh->date->format('d.m.Y') }}: {{ $sh->closed ? '🔒 geschlossen' : substr($sh->opens_at, 0, 5) . '–' . substr($sh->closes_at, 0, 5) }}
                        @if($sh->label)({{ $sh->label }})@endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('addHour')?.addEventListener('click', () => {
        const container = document.getElementById('hoursContainer');
        const i = Date.now();
        const div = document.createElement('div');
        div.className = 'hour-row flex items-center gap-2';
        div.innerHTML = `
            <select name="hours[${i}][weekday]" class="rounded-lg border-stone-200">
                ${['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'].map((d, idx) => `<option value="${idx}">${d}</option>`).join('')}
            </select>
            <input type="time" name="hours[${i}][opens_at]" value="12:00" class="rounded-lg border-stone-200">
            <span>–</span>
            <input type="time" name="hours[${i}][closes_at]" value="22:00" class="rounded-lg border-stone-200">
            <input type="text" name="hours[${i}][service_name]" placeholder="Service" class="w-24 rounded-lg border-stone-200">
            <button type="button" onclick="this.closest('.hour-row').remove()" class="text-red-500">✕</button>`;
        container.appendChild(div);
    });
</script>
@endsection

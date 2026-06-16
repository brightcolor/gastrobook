@extends('layouts.admin')
@section('title', 'Einstellungen')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Einstellungen – {{ $location->name }}</h1>

{{-- Toast notification --}}
<div id="settingsToast"
     class="pointer-events-none fixed bottom-6 right-6 z-50 hidden max-w-sm translate-y-2 rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300 opacity-0"
     role="alert" aria-live="polite"></div>

{{-- Betriebstyp --}}
@php $tenant = app(\App\Support\TenantContext::class)->tenant(); @endphp
<div class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-3 font-bold">Betriebstyp</h2>
    <p class="mb-3 text-sm text-stone-500">Bestimmt das Buchungsmodell für diesen Mandanten. Umschalten ändert die Navigation und die öffentliche Buchungsseite.</p>
    <form method="POST" action="{{ route('admin.settings.tenant-type') }}" class="flex flex-wrap items-end gap-4">
        @csrf @method('PUT')
        <div class="flex gap-3">
            @foreach(\App\Enums\TenantType::cases() as $type)
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border-2 px-4 py-3 text-sm font-semibold
                    {{ $tenant->type === $type ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-stone-200 hover:border-stone-400' }}">
                    <input type="radio" name="type" value="{{ $type->value }}"
                           @checked($tenant->type === $type) class="sr-only">
                    {{ $type->icon() }} {{ $type->label() }}
                </label>
            @endforeach
        </div>
        <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
            Speichern
        </button>
    </form>
</div>

@php
    $bookableLocations = $location->tenant->locations()->where('is_active', true)->where('online_booking_enabled', true)->count();
    $bookingUrl = $bookableLocations <= 1
        ? route('booking.landing', $location->tenant->slug)
        : route('booking.show', [$location->tenant->slug, $location->slug]);
    $useShortWidgetUrl = $location->slug === $location->tenant->slug || $bookableLocations <= 1;
    $widgetEmbedSrc = $useShortWidgetUrl
        ? route('booking.embed.single', $location->tenant->slug)
        : route('booking.embed', [$location->tenant->slug, $location->slug]);
    $widgetPopupSrc = $useShortWidgetUrl
        ? route('booking.widget.popup.single', $location->tenant->slug)
        : route('booking.widget.popup', [$location->tenant->slug, $location->slug]);
@endphp
<div class="mb-4 rounded-2xl bg-white p-4 text-sm shadow-sm">
    Öffentliche Buchungsseite:
    <a href="{{ $bookingUrl }}" target="_blank" class="font-mono text-teal-700 underline">{{ $bookingUrl }}</a>
    @if($bookableLocations > 1)
        <p class="mt-1 text-xs text-stone-500">Mehrere Standorte aktiv – unter <span class="font-mono">/book/{{ $location->tenant->slug }}</span> können Gäste den Standort wählen.</p>
    @endif
</div>

{{-- Website-Widget --}}
<div class="mb-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-1 font-bold">Website-Widget</h2>
    <p class="mb-4 text-xs text-stone-500">Binde die Buchungsfunktion direkt auf deiner Website ein. Wähle den Typ, der am besten passt.</p>

    {{-- Tabs --}}
    <div class="mb-4 flex gap-1 rounded-xl bg-stone-100 p-1 text-sm" role="tablist">
        <button class="widget-tab flex-1 rounded-lg bg-white px-3 py-1.5 font-semibold shadow-sm" data-tab="popup" role="tab" aria-selected="true">Popup-Button</button>
        <button class="widget-tab flex-1 rounded-lg px-3 py-1.5 font-medium text-stone-500" data-tab="inline" role="tab" aria-selected="false">Eingebettet</button>
        <button class="widget-tab flex-1 rounded-lg px-3 py-1.5 font-medium text-stone-500" data-tab="link" role="tab" aria-selected="false">Direktlink</button>
    </div>

    {{-- Popup tab --}}
    <div id="widgetTab-popup" role="tabpanel">
        <p class="mb-3 text-xs text-stone-500">Ein Klick öffnet das Buchungsformular als Overlay – kein Seitenwechsel, kein neuer Tab. Ideal für alle Websites.</p>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div>
                <label for="wLabel" class="mb-1 block text-xs font-semibold">Button-Text</label>
                <input id="wLabel" value="Jetzt reservieren" class="w-full rounded-xl border-2 border-stone-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="wColor" class="mb-1 block text-xs font-semibold">Farbe</label>
                <input type="color" id="wColor" value="{{ $tenant->brand_primary_color ?: '#0d9488' }}" class="h-10 w-full cursor-pointer rounded-xl border-2 border-stone-200">
            </div>
        </div>
        <label class="mb-3 flex items-center gap-2 text-sm">
            <input type="checkbox" id="wFloat" class="rounded"> Floating-Button (klebt unten rechts auf der Seite)
        </label>
        <div class="relative">
            <pre id="wSnippetPopup" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
            <button onclick="swayyWidgetCopy('wSnippetPopup',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
        </div>
        <p class="mt-3 text-xs text-stone-400">Vor dem schließenden <code>&lt;/body&gt;</code>-Tag einfügen. Funktioniert mit WordPress, Squarespace, Webflow und jeder anderen Website.</p>
    </div>

    {{-- Inline/iframe tab --}}
    <div id="widgetTab-inline" class="hidden" role="tabpanel">
        <p class="mb-3 text-xs text-stone-500">Das Buchungsformular erscheint direkt auf der Seite als eingebettetes Formular. Ideal für eine eigene Reservierungs-Unterseite.</p>
        <div class="relative">
            <pre id="wSnippetInline" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
            <button onclick="swayyWidgetCopy('wSnippetInline',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
        </div>
        <p class="mt-3 text-xs text-stone-400">Das <code>div#swayy-widget</code> zeigt, wo das Formular erscheint. Das Script lädt es automatisch als responsive iFrame.</p>
    </div>

    {{-- Link tab --}}
    <div id="widgetTab-link" class="hidden" role="tabpanel">
        <p class="mb-3 text-xs text-stone-500">Ein einfacher HTML-Button-Link zur Buchungsseite – kein JavaScript, maximale Kompatibilität.</p>
        <div class="mb-3 grid grid-cols-2 gap-3">
            <div>
                <label for="wLinkLabel" class="mb-1 block text-xs font-semibold">Button-Text</label>
                <input id="wLinkLabel" value="Jetzt reservieren" class="w-full rounded-xl border-2 border-stone-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="wLinkColor" class="mb-1 block text-xs font-semibold">Farbe</label>
                <input type="color" id="wLinkColor" value="{{ $tenant->brand_primary_color ?: '#0d9488' }}" class="h-10 w-full cursor-pointer rounded-xl border-2 border-stone-200">
            </div>
        </div>
        <div class="relative">
            <pre id="wSnippetLink" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
            <button onclick="swayyWidgetCopy('wSnippetLink',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
        </div>
    </div>
</div>

@if(auth()->user()->canInTenant('tenant.settings.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
<div class="mb-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-1 font-bold">Logo dieses Standorts</h2>
    <p class="mb-3 text-xs text-stone-500">Erscheint oben auf der Buchungsseite. PNG, JPG oder WebP, max. 3 MB.</p>
    <div class="flex flex-wrap items-center gap-4">
        <div id="logoPreviewWrap" class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50">
            @if($location->brand_logo_path)
                <img id="logoPreviewImg" src="{{ route('brand.location.logo', [$location->tenant->slug, $location->slug]) }}?t={{ now()->timestamp }}" alt="Logo" class="h-full w-full object-contain">
            @else
                <span id="logoPlaceholder" class="text-2xl text-stone-300">🍽</span>
            @endif
        </div>
        <form method="POST" action="{{ route('admin.settings.logo.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
            @csrf
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required
                   class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-900 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white">
            <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Hochladen</button>
        </form>
        @if($location->brand_logo_path)
            <form method="POST" action="{{ route('admin.settings.logo.delete') }}" onsubmit="return confirm('Logo entfernen?')">
                @csrf @method('DELETE')
                <button class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-600 hover:bg-stone-200">Entfernen</button>
            </form>
        @endif
    </div>
</div>
@endif

<div class="grid gap-6 xl:grid-cols-2">
    {{-- Booking rules --}}
    <form method="POST" action="{{ route('admin.settings.booking-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
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
            <label class="flex items-center gap-2"><input type="checkbox" name="require_email_confirmation" value="1" @checked($settings->require_email_confirmation)> E-Mail-Bestätigung verlangen (Gast bestätigt seine Adresse beim ersten Buchen)</label>
            @if($tenant->isSalon())
                <label class="flex items-center gap-2"><input type="checkbox" name="gap_optimization_enabled" value="1" @checked($settings->gap_optimization_enabled)> Lückenoptimierer (bei „Beliebig" Mitarbeiter so wählen, dass möglichst wenig Leerlauf entsteht)</label>
            @else
                <label class="flex items-center gap-2"><input type="checkbox" name="public_floorplan_enabled" value="1" @checked($settings->public_floorplan_enabled)> Öffentlichen Tischplan auf der Buchungsseite zeigen (Gäste sehen freie Tische und können einen wählen)</label>
            @endif
        </div>
        <div class="mt-4 border-t border-stone-100 pt-3">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Buchungsbestätigung</h3>
            <div class="space-y-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="confetti_on_booking" value="1" @checked($settings->confetti_on_booking)> Konfetti-Animation nach erfolgter Buchung</label>
                <div class="flex items-center gap-3">
                    <span class="shrink-0 text-stone-600">Gäste ansprechen mit</span>
                    <select name="guest_address" class="rounded-lg border-stone-200 text-sm">
                        <option value="Sie" @selected($settings->guest_address === 'Sie')>Sie (formell)</option>
                        <option value="du" @selected($settings->guest_address === 'du')>du (informell)</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="mt-4 border-t border-stone-100 pt-3">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Erinnerungen</h3>
            <div class="flex flex-wrap items-end gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="reminder_enabled" value="1" @checked($settings->reminder_enabled)> E-Mail-Erinnerung aktiv</label>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Stunden vorher</label>
                    <input type="number" name="reminder_hours_before" min="1" max="168" value="{{ $settings->reminder_hours_before }}" class="w-24 rounded-lg border-stone-200"></div>
                <label class="flex items-center gap-2"><input type="checkbox" name="sms_reminder_enabled" value="1" @checked($settings->sms_reminder_enabled)> SMS-Erinnerung aktiv</label>
            </div>
            <p class="mt-1 text-xs text-stone-400">SMS-Erinnerungen erfordern eine konfigurierte seven.io-Integration (siehe unten) und eine Telefonnummer beim Gast.</p>
        </div>
        <div class="mt-4 border-t border-stone-100 pt-3">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Rückerstattung der Anzahlung</h3>
            <div class="flex flex-wrap items-end gap-4 text-sm">
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Modus</label>
                    <select name="refund_mode" class="w-full rounded-lg border-stone-200">
                        <option value="off" @selected($settings->refund_mode === 'off')>Aus</option>
                        <option value="manual" @selected($settings->refund_mode === 'manual')>Manuell (Freigabe durch Personal)</option>
                        <option value="auto" @selected($settings->refund_mode === 'auto')>Automatisch</option>
                    </select></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Erstattung in %</label>
                    <input type="number" name="refund_percent" min="0" max="100" value="{{ $settings->refund_percent }}" class="w-24 rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Ausführung</label>
                    <select name="refund_processing" class="w-full rounded-lg border-stone-200">
                        <option value="immediate" @selected($settings->refund_processing === 'immediate')>Sofort</option>
                        <option value="scheduled" @selected($settings->refund_processing === 'scheduled')>Nach Zeitplan (Sammellauf)</option>
                    </select></div>
            </div>
            <p class="mt-1 text-xs text-stone-400">Greift bei Storno innerhalb der Frist. Nach Frist und bei No-Show erfolgt keine Erstattung. Offene Freigaben unter „Rückerstattungen".</p>
        </div>
        <button class="mt-4 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>

    {{-- Opening hours --}}
    <form method="POST" action="{{ route('admin.settings.opening-hours') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
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

    {{-- Räume --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Räume</h2>
        <p class="mb-3 text-xs text-stone-500">Räume anlegen, Tische und Kombinationen dann im <a href="{{ route('admin.floorplan.index') }}" class="font-semibold text-teal-700 underline">Tischplan</a> verwalten.</p>
        <form method="POST" action="{{ route('admin.settings.rooms.store') }}" class="flex items-end gap-2 text-sm">
            @csrf
            <div class="grow"><label class="mb-1 block text-xs font-semibold text-stone-500">Neuer Raum</label>
                <input type="text" name="name" required placeholder="z. B. Wintergarten" class="w-full rounded-lg border-stone-200"></div>
            <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="is_outdoor" value="1"> Außen</label>
            <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
        </form>
    </div>

    {{-- Tags --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100" id="tagSection">
        <h2 class="mb-1 font-bold">Reservierungs-Tags</h2>
        <p class="mb-3 text-xs text-stone-500">Tags helfen beim schnellen Erkennen besonderer Reservierungen im Tischplan (VIP, Allergiker, Geburtstag, …).</p>
        <div id="settingsTagList" class="mb-3 flex flex-wrap gap-2 text-sm"></div>
        <div class="flex items-end gap-2">
            <div class="grow">
                <label class="mb-1 block text-xs font-semibold text-stone-500">Neuer Tag</label>
                <input id="settingsTagName" type="text" maxlength="40" placeholder="z. B. VIP"
                       class="w-full rounded-lg border-stone-200 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe</label>
                <input id="settingsTagColor" type="color" value="#6b7280" class="h-9 w-10 cursor-pointer rounded-lg border-stone-200">
            </div>
            <button id="settingsTagCreate" class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
        </div>
    </div>

    {{-- Booking form fields --}}
    @if(auth()->user()->canInTenant('tenant.settings.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
    <form method="POST" action="{{ route('admin.settings.field-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
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
    <form method="POST" action="{{ route('admin.settings.mailwizz') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
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

    {{-- Stripe + deposit rules --}}
    @if(auth()->user()->canInTenant('integrations.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
    <div class="space-y-6">
        <form method="POST" action="{{ route('admin.settings.stripe') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            @csrf @method('PUT')
            <div class="mb-1 flex items-center justify-between">
                <h2 class="font-bold">Zahlungen: Stripe</h2>
                @if($stripe)
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                        {{ ['connected' => 'bg-emerald-100 text-emerald-800'][$stripe->status] ?? 'bg-stone-100 text-stone-600' }}">
                        {{ ['connected' => 'verbunden', 'disconnected' => 'deaktiviert'][$stripe->status] ?? $stripe->status }}
                    </span>
                @endif
            </div>
            <p class="mb-3 text-xs text-stone-500">
                Für Event-Vorauszahlungen und Reservierungs-Anzahlungen (No-Show-Schutz).
                Es werden keine Kartendaten gespeichert – die Zahlung läuft komplett über Stripe Checkout.
            </p>
            <div class="space-y-2 text-sm">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Secret Key (sk_…) {{ $stripe ? '– leer lassen zum Beibehalten' : '' }}</label>
                    <input type="password" name="secret_key" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Webhook-Signing-Secret (whsec_…)</label>
                    <input type="password" name="webhook_secret" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($stripe->status ?? '') === 'connected')> Online-Zahlung aktiv</label>
            </div>
            <p class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-600">
                Webhook-URL in Stripe hinterlegen: <code class="break-all">{{ route('webhooks.stripe') }}</code><br>
                Events: <code>checkout.session.completed</code>, <code>checkout.session.expired</code>
            </p>
            <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
        </form>

        {{-- PayPal --}}
        <form method="POST" action="{{ route('admin.settings.paypal') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            @csrf @method('PUT')
            <div class="mb-1 flex items-center justify-between">
                <h2 class="font-bold">Zahlungen: PayPal</h2>
                @if($paypal)
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                        {{ ['connected' => 'bg-emerald-100 text-emerald-800'][$paypal->status] ?? 'bg-stone-100 text-stone-600' }}">
                        {{ ['connected' => 'verbunden', 'disconnected' => 'deaktiviert'][$paypal->status] ?? $paypal->status }}
                    </span>
                @endif
            </div>
            <p class="mb-3 text-xs text-stone-500">
                Alternativ oder zusätzlich zu Stripe – ist beides aktiv, wählt der Gast an der Kasse.
                Zahlungen gehen direkt auf Ihr PayPal-Konto. Zugangsdaten unter
                <a href="https://developer.paypal.com" target="_blank" rel="noopener" class="underline">developer.paypal.com</a> (REST-App).
            </p>
            <div class="space-y-2 text-sm">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Client-ID {{ $paypal ? '– leer lassen zum Beibehalten' : '' }}</label>
                    <input type="password" name="client_id" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Secret {{ $paypal ? '– leer lassen zum Beibehalten' : '' }}</label>
                    <input type="password" name="secret" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Modus</label>
                    <select name="mode" class="w-full rounded-lg border-stone-200">
                        <option value="live" @selected(($paypalCredentials['mode'] ?? 'live') === 'live')>Live (echte Zahlungen)</option>
                        <option value="sandbox" @selected(($paypalCredentials['mode'] ?? '') === 'sandbox')>Sandbox (Test)</option>
                    </select>
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($paypal->status ?? '') === 'connected')> PayPal-Zahlung aktiv</label>
            </div>
            <p class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-600">
                Keine Webhook-Konfiguration nötig – die Zahlung wird bei der Rückkehr des Gastes erfasst (Capture-on-Return).
            </p>
            <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
        </form>

        {{-- SMS: seven.io --}}
        <form method="POST" action="{{ route('admin.settings.sms') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            @csrf @method('PUT')
            <div class="mb-1 flex items-center justify-between">
                <h2 class="font-bold">SMS: seven.io</h2>
                @if($sms)
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                        {{ ['connected' => 'bg-emerald-100 text-emerald-800', 'error' => 'bg-red-100 text-red-800'][$sms->status] ?? 'bg-stone-100 text-stone-600' }}">
                        {{ ['connected' => 'verbunden', 'disconnected' => 'deaktiviert', 'error' => 'Fehler'][$sms->status] ?? $sms->status }}
                    </span>
                @endif
            </div>
            <p class="mb-3 text-xs text-stone-500">
                Deutscher Anbieter (DSGVO-konform) für Termin-Erinnerungen per SMS – senkt No-Shows.
                API-Key unter <a href="https://app.seven.io" target="_blank" rel="noopener" class="underline">app.seven.io</a> → Einstellungen → API.
            </p>
            <div class="space-y-2 text-sm">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">API-Key {{ $sms ? '– leer lassen zum Beibehalten' : '' }}</label>
                    <input type="password" name="api_key" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Absender (Name max. 11 Zeichen oder Nummer)</label>
                    <input type="text" name="sender_id" maxlength="16" value="{{ $smsCredentials['sender_id'] ?? '' }}"
                           placeholder="z. B. Salon Anna" class="w-full rounded-lg border-stone-200">
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($sms->status ?? '') === 'connected')> SMS-Versand aktiv</label>
            </div>
            <p class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-600">
                Aktivierung der SMS-Erinnerung erfolgt zusätzlich pro Standort unter „Buchungsregeln → Erinnerungen".
            </p>
            <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
        </form>

        @if(auth()->user()->canInTenant('payments.manage', app(\App\Support\TenantContext::class)->tenant(), $location))
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-1 font-bold">Anzahlungsregeln (No-Show-Schutz)</h2>
            <p class="mb-3 text-xs text-stone-500">
                Online-Reservierungen, die eine Regel treffen, müssen vorab anzahlen.
                Der Betrag wird beim Besuch mit der Rechnung verrechnet; bei Nichterscheinen erfolgt keine Rückerstattung
                (wird Gästen so angezeigt).
            </p>
            <form method="POST" action="{{ route('admin.settings.deposit-rules.store') }}" class="grid grid-cols-2 gap-2 text-sm">
                @csrf
                <input type="text" name="name" required placeholder="Name (z. B. Gruppen ab 6) *" class="col-span-2 rounded-lg border-stone-200">
                <div><label class="mb-1 block text-xs text-stone-500">Ab Personenzahl</label>
                    <input type="number" name="min_party_size" min="1" placeholder="z. B. 6" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Betrag p. P. (€) *</label>
                    <input type="number" name="amount_per_person" required step="0.01" min="0" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Nur ab Uhrzeit</label>
                    <input type="time" name="from_time" class="w-full rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs text-stone-500">Zahlungsfrist (Min.)</label>
                    <input type="number" name="payment_deadline_minutes" min="10" placeholder="60" class="w-full rounded-lg border-stone-200"></div>
                <button class="col-span-2 rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Regel anlegen</button>
            </form>
            <div class="mt-3 space-y-1 text-sm">
                @foreach($depositRules as $rule)
                    <div class="flex items-center justify-between rounded-lg bg-stone-50 px-3 py-2">
                        <span>
                            <strong>{{ $rule->name }}</strong>
                            @if($rule->min_party_size) · ab {{ $rule->min_party_size }} P. @endif
                            · {{ number_format($rule->amount_per_person_minor / 100, 2, ',', '.') }} € p. P.
                            @if($rule->from_time) · ab {{ substr($rule->from_time, 0, 5) }} Uhr @endif
                        </span>
                        <form method="POST" action="{{ route('admin.settings.deposit-rules.delete', $rule) }}" onsubmit="return confirm('Regel löschen?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500">✕</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- Special hours --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
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
        <div class="mt-3 space-y-1 text-sm" id="specialHoursList">
            @foreach($specialHours as $sh)
                <div class="rounded-lg bg-stone-50 px-3 py-2">
                    {{ $sh->date->format('d.m.Y') }}: {{ $sh->closed ? '🔒 geschlossen' : substr($sh->opens_at, 0, 5) . '–' . substr($sh->closes_at, 0, 5) }}
                    @if($sh->label)({{ $sh->label }})@endif
                </div>
            @endforeach
        </div>
    </div>
</div>

<script>
    document.getElementById('addHour')?.addEventListener('click', () => {
        const container = document.getElementById('hoursContainer');
        const i = Date.now();
        const nextDay = Math.min(container.querySelectorAll('.hour-row').length, 6);
        const div = document.createElement('div');
        div.className = 'hour-row flex items-center gap-2';
        div.innerHTML = `
            <select name="hours[${i}][weekday]" class="rounded-lg border-stone-200">
                ${['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'].map((d, idx) => `<option value="${idx}" ${idx === nextDay ? 'selected' : ''}>${d}</option>`).join('')}
            </select>
            <input type="time" name="hours[${i}][opens_at]" value="12:00" class="rounded-lg border-stone-200">
            <span>–</span>
            <input type="time" name="hours[${i}][closes_at]" value="22:00" class="rounded-lg border-stone-200">
            <input type="text" name="hours[${i}][service_name]" placeholder="Service (optional)" class="w-28 rounded-lg border-stone-200">
            <button type="button" onclick="this.closest('.hour-row').remove()" class="shrink-0 text-red-500 hover:text-red-700">✕</button>`;
        container.appendChild(div);
    });

    // ── AJAX form interceptor ───────────────────────────────────────────────
    (function () {
        const csrf = @json(csrf_token());
        const toast = document.getElementById('settingsToast');

        function showToast(msg, isErr) {
            toast.textContent = msg;
            toast.className = [
                'pointer-events-none fixed bottom-6 right-6 z-50 max-w-sm rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300',
                isErr ? 'bg-red-600 text-white' : 'bg-stone-900 text-white',
            ].join(' ');
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
            clearTimeout(toast._t);
            toast._t = setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(8px)';
            }, 3200);
        }

        // Scroll-position restore after reload
        const scrollKey = 'sw_settings_scroll';
        const saved = sessionStorage.getItem(scrollKey);
        if (saved !== null) {
            window.scrollTo(0, parseInt(saved, 10));
            sessionStorage.removeItem(scrollKey);
        }

        document.querySelectorAll('form').forEach(form => {
            // Skip DELETE forms (confirmation dialogs – table delete, rule delete, etc.)
            if (form.querySelector('input[name="_method"][value="DELETE"]')) return;

            form.addEventListener('submit', async e => {
                e.preventDefault();

                const btn = form.querySelector('[type=submit]');
                const orig = btn?.textContent;
                if (btn) { btn.disabled = true; btn.textContent = '…'; }

                try {
                    const isMultipart = form.enctype === 'multipart/form-data';
                    const body = isMultipart
                        ? new FormData(form)
                        : new URLSearchParams(new FormData(form));

                    const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf };
                    if (!isMultipart) headers['Content-Type'] = 'application/x-www-form-urlencoded';

                    const res = await fetch(form.action || location.href, {
                        method: form.method?.toUpperCase() || 'POST',
                        headers,
                        body,
                    });

                    let json = {};
                    try { json = await res.json(); } catch {}

                    if (res.ok) {
                        showToast(json.message || 'Gespeichert ✓');

                        // Logo-specific: update preview without reload
                        if (json.logo_url) {
                            const img = document.getElementById('logoPreviewImg');
                            const wrap = document.getElementById('logoPreviewWrap');
                            const placeholder = document.getElementById('logoPlaceholder');
                            if (img) {
                                img.src = json.logo_url + '?t=' + Date.now();
                            } else if (wrap) {
                                wrap.innerHTML = `<img id="logoPreviewImg" src="${json.logo_url}?t=${Date.now()}" alt="Logo" class="h-full w-full object-contain">`;
                            }
                            if (placeholder) placeholder.remove();
                            // Reset file input
                            const fileInput = form.querySelector('input[type=file]');
                            if (fileInput) fileInput.value = '';
                            return;
                        }

                        // Reload if response requests it (list items were created)
                        if (json.reload) {
                            sessionStorage.setItem(scrollKey, String(window.scrollY));
                            setTimeout(() => location.reload(), 700);
                        }
                    } else {
                        const msg = json.errors
                            ? Object.values(json.errors).flat().join(' · ')
                            : (json.message || 'Fehler beim Speichern.');
                        showToast(msg, true);
                    }
                } catch {
                    showToast('Netzwerkfehler – bitte Seite neu laden.', true);
                } finally {
                    if (btn) { btn.disabled = false; if (orig) btn.textContent = orig; }
                }
            });
        });
    })();
</script>

<script>
// ── Tags-Verwaltung ────────────────────────────────────────────────────────
(function () {
    const csrf = @json(csrf_token());
    const indexUrl = @json(route('admin.tags.index'));
    const storeUrl = @json(route('admin.tags.store'));
    const deleteBase = @json(url('/admin/tags'));

    let tags = [];

    async function loadTags() {
        const res = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
        if (res.ok) { tags = await res.json(); renderTags(); }
    }

    function renderTags() {
        const list = document.getElementById('settingsTagList');
        if (!list) return;
        list.innerHTML = tags.length
            ? tags.map(t => `
                <span class="flex items-center gap-1.5 rounded-full px-3 py-1 font-semibold text-sm"
                      style="background:${t.color}22;color:${t.color}">
                    ${t.name}
                    <button class="tag-del opacity-60 hover:opacity-100" data-id="${t.id}" title="Löschen">✕</button>
                </span>`).join('')
            : '<span class="text-sm text-stone-400">Noch keine Tags angelegt.</span>';

        list.querySelectorAll('.tag-del').forEach(btn => btn.addEventListener('click', async () => {
            if (!confirm('Tag „' + tags.find(t => t.id == btn.dataset.id)?.name + '" löschen?')) return;
            btn.disabled = true;
            const res = await fetch(`${deleteBase}/${btn.dataset.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            });
            if (res.ok) { tags = tags.filter(t => t.id != btn.dataset.id); renderTags(); }
            else { btn.disabled = false; alert('Löschen fehlgeschlagen.'); }
        }));
    }

    document.getElementById('settingsTagCreate')?.addEventListener('click', async () => {
        const name = document.getElementById('settingsTagName').value.trim();
        const color = document.getElementById('settingsTagColor').value;
        if (!name) return;
        const res = await fetch(storeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ name, color }),
        });
        if (res.ok) {
            const tag = await res.json();
            if (!tags.find(t => t.id === tag.id)) tags.push(tag);
            else tags = tags.map(t => t.id === tag.id ? tag : t);
            document.getElementById('settingsTagName').value = '';
            renderTags();
        } else {
            const j = await res.json().catch(() => ({}));
            alert(j.message || 'Tag konnte nicht angelegt werden.');
        }
    });

    loadTags();
})();
</script>

<script>
// ── Website-Widget ─────────────────────────────────────────────────────────
(function () {
    var popupSrc  = @json($widgetPopupSrc);
    var embedSrc  = @json($widgetEmbedSrc);
    var bookingUrl = @json($bookingUrl);

    function updateSnippets() {
        var label     = document.getElementById('wLabel')?.value || 'Jetzt reservieren';
        var color     = document.getElementById('wColor')?.value || '#0d9488';
        var isFloat   = document.getElementById('wFloat')?.checked;
        var linkLabel = document.getElementById('wLinkLabel')?.value || 'Jetzt reservieren';
        var linkColor = document.getElementById('wLinkColor')?.value || '#0d9488';

        var popupAttrs = ' data-color="' + color + '"';
        if (label !== 'Jetzt reservieren') popupAttrs = ' data-label="' + label + '"' + popupAttrs;
        if (isFloat) popupAttrs += ' data-float="1"';

        var pop = document.getElementById('wSnippetPopup');
        if (pop) pop.textContent = '<script src="' + popupSrc + '"' + popupAttrs + ' defer><\/script>';

        var inl = document.getElementById('wSnippetInline');
        if (inl) inl.textContent = '<div id="swayy-widget"></div>\n<script src="' + embedSrc + '" defer><\/script>';

        var lnk = document.getElementById('wSnippetLink');
        if (lnk) lnk.textContent =
            '<a href="' + bookingUrl + '" target="_blank"\n' +
            '   style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;\n' +
            '          background:' + linkColor + ';color:#fff;border-radius:10px;\n' +
            '          font-family:inherit;font-size:15px;font-weight:600;text-decoration:none;">\n' +
            '  ' + linkLabel + '\n' +
            '</a>';
    }

    // Tab switching
    document.querySelectorAll('.widget-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.widget-tab').forEach(function (b) {
                b.classList.remove('bg-white', 'shadow-sm', 'font-semibold');
                b.classList.add('font-medium', 'text-stone-500');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('bg-white', 'shadow-sm', 'font-semibold');
            btn.classList.remove('font-medium', 'text-stone-500');
            btn.setAttribute('aria-selected', 'true');

            document.querySelectorAll('[id^="widgetTab-"]').forEach(function (el) { el.classList.add('hidden'); });
            var panel = document.getElementById('widgetTab-' + btn.dataset.tab);
            if (panel) panel.classList.remove('hidden');
        });
    });

    // Live update snippets
    ['wLabel', 'wColor', 'wFloat', 'wLinkLabel', 'wLinkColor'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', updateSnippets); el.addEventListener('change', updateSnippets); }
    });

    updateSnippets();
})();

function swayyWidgetCopy(id, btn) {
    var text = document.getElementById(id)?.textContent;
    if (!text) return;
    navigator.clipboard.writeText(text).then(function () {
        var orig = btn.textContent;
        btn.textContent = 'Kopiert ✓';
        setTimeout(function () { btn.textContent = orig; }, 1800);
    });
}
</script>
@endsection

@extends('layouts.admin')
@section('title', 'Einstellungen')
@section('content')
@php
    $tenant      = app(\App\Support\TenantContext::class)->tenant();
    $canManage   = auth()->user()->canInTenant('tenant.settings.manage', $tenant, $location);
    $canPayments = auth()->user()->canInTenant('payments.manage',        $tenant, $location);
    $canInteg    = auth()->user()->canInTenant('integrations.manage',    $tenant, $location);
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
    $weekdays = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
@endphp

{{-- Tooltip-Styles --}}
<style>
.tip{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#d6d3d1;color:#78716c;font-size:9px;font-weight:800;cursor:help;position:relative;vertical-align:middle;margin-left:4px;flex-shrink:0;line-height:1;}
.tip::before{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#1c1917;color:#fafaf9;border-radius:10px;padding:8px 12px;font-size:12px;line-height:1.5;width:max-content;max-width:280px;white-space:normal;font-weight:400;font-style:normal;opacity:0;pointer-events:none;transition:opacity .12s;z-index:9999;text-align:left;box-shadow:0 4px 16px rgba(0,0,0,.3);}
.tip::after{content:'';position:absolute;bottom:calc(100% + 2px);left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#1c1917;opacity:0;transition:opacity .12s;z-index:9999;}
.tip:hover::before,.tip:focus::before,.tip:hover::after,.tip:focus::after{opacity:1;}
</style>

{{-- Toast --}}
<div id="settingsToast"
     class="pointer-events-none fixed bottom-6 right-6 z-50 hidden max-w-sm translate-y-2 rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300 opacity-0"
     role="alert" aria-live="polite"></div>

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Einstellungen – {{ $location->name }}</h1>
    <a href="{{ $bookingUrl }}" target="_blank"
       class="rounded-lg bg-stone-100 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-stone-200">
        Buchungsseite ansehen ↗
    </a>
</div>

{{-- ─── Tab bar ─────────────────────────────────────────────────────────── --}}
<div class="mb-6 flex flex-wrap gap-1 rounded-2xl bg-stone-100 p-1.5" role="tablist" id="settingsTabBar">
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="allgemein" role="tab">Allgemein</button>
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="buchungsregeln" role="tab">Buchungsregeln</button>
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="zeiten" role="tab">Zeiten</button>
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="raeume" role="tab">Räume & Tags</button>
    @if($canPayments)
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="zahlungen" role="tab">Zahlungen</button>
    @endif
    @if($canInteg)
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="integrationen" role="tab">Integrationen</button>
    @endif
    <button class="settings-tab rounded-xl px-4 py-2 text-sm font-semibold" data-tab="widget" role="tab">Website-Widget</button>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Allgemein
     ═══════════════════════════════════════════════════════════════════════ --}}
<div id="tab-allgemein" class="settings-panel space-y-5">

    {{-- Betriebstyp --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Betriebstyp <span class="tip" tabindex="0" data-tip="Legt fest, ob dein Betrieb als Restaurant mit Tischreservierungen oder als Salon mit Terminbuchungen pro Mitarbeiter arbeitet. Du kannst jederzeit umschalten – alles passt sich automatisch an.">?</span></h2>
        <p class="mb-3 text-sm text-stone-500">Bestimmt das Buchungsmodell für diesen Mandanten. Umschalten ändert Navigation und Buchungsseite.</p>
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

    {{-- Markenfarbe --}}
    @if($canManage)
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Markenfarbe <span class="tip" tabindex="0" data-tip="Deine Hauptfarbe erscheint auf der Buchungsseite und im Website-Buchungsbutton. Am besten deine Logo-Farbe wählen – so wirkt alles aus einem Guss.">?</span></h2>
        <p class="mb-3 text-sm text-stone-500">Wird auf der öffentlichen Buchungsseite und im Website-Widget verwendet.</p>
        <form method="POST" action="{{ route('admin.settings.branding') }}" class="flex flex-wrap items-end gap-4">
            @csrf @method('PUT')
            <div>
                <label for="brandColor" class="mb-1 block text-xs font-semibold text-stone-500">Primärfarbe <span class="tip" tabindex="0" data-tip="Klick auf das Farbfeld und wähle deine Wunschfarbe. Die Vorschau rechts zeigt sofort, wie dein Buchungsbutton aussehen wird.">?</span></label>
                <input type="color" id="brandColor" name="brand_primary_color"
                       value="{{ $tenant->brand_primary_color ?: '#0d9488' }}"
                       class="h-10 w-20 cursor-pointer rounded-xl border-2 border-stone-200">
            </div>
            <div id="brandColorPreview" class="flex items-center gap-3">
                <span class="rounded-xl px-4 py-2 text-sm font-semibold text-white"
                      style="background:{{ $tenant->brand_primary_color ?: '#0d9488' }}">
                    Jetzt reservieren
                </span>
                <span class="text-xs text-stone-400">Vorschau</span>
            </div>
            <button type="submit" class="rounded-lg bg-stone-900 px-5 py-2 text-sm font-bold text-white">
                Speichern
            </button>
        </form>
    </div>
    @endif

    {{-- Logo --}}
    @if($canManage)
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Logo dieses Standorts <span class="tip" tabindex="0" data-tip="Dein Logo erscheint oben auf der Buchungsseite und gibt deinem Auftritt einen professionellen Look. Am besten ein transparentes PNG – so sieht es auf jedem Hintergrund perfekt aus.">?</span></h2>
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

    {{-- Buchungslink --}}
    <div class="rounded-2xl bg-white p-4 text-sm shadow-sm ring-1 ring-stone-100">
        <p class="mb-1 font-semibold text-stone-600">Öffentliche Buchungsseite</p>
        <a href="{{ $bookingUrl }}" target="_blank" class="break-all font-mono text-teal-700 underline">{{ $bookingUrl }}</a>
        @if($bookableLocations > 1)
            <p class="mt-1 text-xs text-stone-500">Mehrere Standorte aktiv – unter <span class="font-mono">/book/{{ $location->tenant->slug }}</span> können Gäste den Standort wählen.</p>
        @endif
    </div>

    {{-- Formularfelder --}}
    @if($canManage)
    <form method="POST" action="{{ route('admin.settings.field-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        @csrf @method('PUT')
        <h2 class="mb-1 font-bold">Formularfelder im Buchungswidget <span class="tip" tabindex="0" data-tip="Bestimme, welche Felder auf deiner Buchungsseite erscheinen und ob sie Pflicht oder freiwillig sind. 'Ausgeblendet' heißt, das Feld ist für Gäste komplett unsichtbar. Der Name des Gastes ist immer Pflicht.">?</span></h2>
        <p class="mb-3 text-xs text-stone-500">Steuert pro Feld, ob es Gästen angezeigt wird und ob es Pflicht ist. Name ist immer Pflicht.</p>
        <div class="space-y-2 text-sm">
            @php($fieldTips = [
                'email'     => ['E-Mail',                         'Wird für die Buchungsbestätigung und den Storno-Link benötigt. Wir empfehlen mindestens "Optional" – ohne E-Mail kann kein Gast seine Buchung verwalten.'],
                'phone'     => ['Telefon',                        'Hilfreich wenn du Gäste anrufen oder SMS-Erinnerungen schicken möchtest. "Optional" reicht für die meisten Betriebe.'],
                'occasion'  => ['Anlass',                         'Gäste können angeben ob sie Geburtstag feiern oder ein Jubiläum begehen – so kannst du sie schon im Voraus mit einem kleinen Extra überraschen.'],
                'allergies' => ['Allergien / Unverträglichkeiten','Sehr wertvoll für die Küche! Gäste tragen Hinweise zu Nüssen, Laktose, Gluten & Co. ein – du weißt Bescheid bevor sie ankommen.'],
                'note'      => ['Anmerkung',                      'Ein freies Feld für alles Weitere: Sonderwünsche, Fensterplatz-Anfragen oder ob der Geburtstagskuchen schon im Kühlschrank warten soll. 🎂'],
            ])
            @foreach($fieldTips as $field => [$label, $tip])
                <div class="flex items-center justify-between gap-3">
                    <span>{{ $label }} <span class="tip" tabindex="0" data-tip="{{ $tip }}">?</span></span>
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
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Buchungsregeln
     ═══════════════════════════════════════════════════════════════════════ --}}
<div id="tab-buchungsregeln" class="settings-panel hidden">
    <form method="POST" action="{{ route('admin.settings.booking-rules') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        @csrf @method('PUT')
        <h2 class="mb-4 font-bold">Buchungsregeln</h2>

        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Slot-Intervall (Min.) <span class="tip" tabindex="0" data-tip="In welchem Rhythmus zeigt die Buchungsseite Uhrzeiten an? Bei 30 Minuten sehen Gäste z. B. 12:00, 12:30, 13:00 …">?</span></label>
                <select name="slot_interval_minutes" class="w-full rounded-lg border-stone-200">
                    @foreach([15, 30, 60] as $i)<option value="{{ $i }}" @selected($settings->slot_interval_minutes == $i)>{{ $i }}</option>@endforeach
                </select></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Standarddauer (Min.) <span class="tip" tabindex="0" data-tip="Wie lange dauert ein typischer Besuch? Das System reserviert diese Zeit pro Buchung. Restaurants: oft 90–120 Min., Salon: richtet sich nach der Leistung.">?</span></label>
                <input type="number" name="default_duration_minutes" value="{{ $settings->default_duration_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Puffer zw. Res. (Min.) <span class="tip" tabindex="0" data-tip="Diese Mini-Pause reserviert das System zwischen zwei Buchungen am gleichen Tisch – z. B. für Reinigung oder Tisch eindecken. 15–30 Minuten sind üblich.">?</span></label>
                <input type="number" name="buffer_minutes" value="{{ $settings->buffer_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Mindestvorlauf (Min.) <span class="tip" tabindex="0" data-tip="Wie früh muss eine Buchung spätestens eingehen? Bei 60 Minuten kann kein Gast mehr für &#39;gleich&#39; buchen – du hast immer etwas Vorbereitungszeit.">?</span></label>
                <input type="number" name="min_lead_minutes" value="{{ $settings->min_lead_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Vorausbuchung (Tage) <span class="tip" tabindex="0" data-tip="Wie weit im Voraus dürfen Gäste buchen? Bei 90 Tagen ist der Kalender 3 Monate offen. Für Events gerne höher stellen.">?</span></label>
                <input type="number" name="max_advance_days" value="{{ $settings->max_advance_days }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Stornofrist (Min.) <span class="tip" tabindex="0" data-tip="Bis wie viele Minuten vor dem Termin darf kostenlos storniert werden? Danach ist der Storno-Link inaktiv – schützt vor Last-Minute-Absagen.">?</span></label>
                <input type="number" name="cancellation_deadline_minutes" value="{{ $settings->cancellation_deadline_minutes }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Min. Personen online <span class="tip" tabindex="0" data-tip="Die kleinste Gruppe, die online buchen darf. Möchtest du keine Einzeltische online vergeben, stelle dies auf 2.">?</span></label>
                <input type="number" name="min_party_online" value="{{ $settings->min_party_online }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Personen online <span class="tip" tabindex="0" data-tip="Größere Gruppen sollen sich lieber telefonisch melden – für alles darüber zeigt die Buchungsseite keine Verfügbarkeit an.">?</span></label>
                <input type="number" name="max_party_online" value="{{ $settings->max_party_online }}" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs font-semibold text-stone-500">Kapazitätsmodus <span class="tip" tabindex="0" data-tip="&#39;Tischbasiert&#39; prüft ob noch Tische frei sind. &#39;Personenbasiert&#39; zählt Gesamtplätze. &#39;Hybrid&#39; kombiniert beides – ideal wenn du sowohl kleine als auch große Tische hast.">?</span></label>
                <select name="capacity_mode" class="w-full rounded-lg border-stone-200">
                    <option value="table"  @selected($settings->capacity_mode === 'table')>Tischbasiert</option>
                    <option value="person" @selected($settings->capacity_mode === 'person')>Personenbasiert</option>
                    <option value="hybrid" @selected($settings->capacity_mode === 'hybrid')>Hybrid</option>
                </select></div>
            <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-xs font-semibold text-stone-500">Max. Gäste pro Slot (Person/Hybrid) <span class="tip" tabindex="0" data-tip="Im Personen- oder Hybrid-Modus: Wie viele Gäste dürfen gleichzeitig da sein? Ist diese Zahl erreicht, zeigt die Buchungsseite keine freien Zeiten mehr an.">?</span></label>
                <input type="number" name="max_covers_per_slot" value="{{ $settings->max_covers_per_slot }}" class="w-full rounded-lg border-stone-200"></div>
        </div>

        <div class="mt-4 border-t border-stone-100 pt-4">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Online-Buchung</h3>
            <div class="space-y-1.5 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="auto_confirm" value="1" @checked($settings->auto_confirm)> Online-Reservierungen automatisch bestätigen <span class="tip" tabindex="0" data-tip="Buchungen werden sofort bestätigt – kein manueller Klick nötig. Super wenn du immer ausreichend Kapazität hast und deinen Gästen schnell Sicherheit geben willst.">?</span></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="request_only" value="1" @checked($settings->request_only)> Nur als Anfrage annehmen <span class="tip" tabindex="0" data-tip="Gäste schicken eine Anfrage und du entscheidest, ob du bestätigst oder absagst. Gibt dir maximale Kontrolle über deinen Betrieb.">?</span></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="waitlist_enabled" value="1" @checked($settings->waitlist_enabled)> Warteliste aktiv <span class="tip" tabindex="0" data-tip="Wenn nichts mehr frei ist, können sich Gäste auf die Warteliste setzen. Springt jemand ab, erhalten sie automatisch ein Angebot per E-Mail – kein Platz geht verloren!">?</span></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="walkins_enabled" value="1" @checked($settings->walkins_enabled)> Walk-ins aktiv <span class="tip" tabindex="0" data-tip="Ermöglicht deinem Personal, Laufgäste direkt im Live-Board auf einen freien Tisch zu setzen – schnell, einfach, ohne Papierkram.">?</span></label>
                <label class="flex items-center gap-2"><input type="checkbox" name="require_email_confirmation" value="1" @checked($settings->require_email_confirmation)> E-Mail-Bestätigung verlangen <span class="tip" tabindex="0" data-tip="Beim ersten Buchen klickt der Gast auf einen Bestätigungslink in seiner E-Mail – so weißt du, dass die Adresse stimmt. Schützt effektiv vor Fake-Buchungen.">?</span></label>
                @if($tenant->isSalon())
                    <label class="flex items-center gap-2"><input type="checkbox" name="gap_optimization_enabled" value="1" @checked($settings->gap_optimization_enabled)> Lückenoptimierer <span class="tip" tabindex="0" data-tip="Wählt bei &#39;Beliebig&#39;-Buchungen automatisch den Mitarbeiter, dessen Kalender dadurch am besten gefüllt wird. Weniger Leerlauf – mehr Effizienz für dein Team!">?</span></label>
                @else
                    <label class="flex items-center gap-2"><input type="checkbox" name="public_floorplan_enabled" value="1" @checked($settings->public_floorplan_enabled)> Öffentlichen Tischplan zeigen <span class="tip" tabindex="0" data-tip="Gäste sehen auf der Buchungsseite deinen Grundriss und können ihren Lieblingsplatz aussuchen – perfekt für besondere Tische am Fenster oder auf der Terrasse.">?</span></label>
                @endif
            </div>
        </div>

        <div class="mt-4 border-t border-stone-100 pt-4">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Buchungsbestätigung</h3>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="confetti_on_booking" value="1" @checked($settings->confetti_on_booking)> Konfetti-Animation nach erfolgter Buchung <span class="tip" tabindex="0" data-tip="Lässt nach erfolgreicher Buchung Konfetti über die Bestätigungsseite regnen. Ein kleiner Wow-Moment für deine Gäste – macht Lust auf den Besuch! 🎉">?</span></label>
                <div class="flex items-center gap-2">
                    <span class="text-stone-600">Gäste ansprechen mit <span class="tip" tabindex="0" data-tip="Bestimmt die Anrede in allen E-Mails und auf der Buchungsseite. &#39;Sie&#39; wirkt formell und professionell, &#39;du&#39; persönlich und nahbar – ganz wie es zu deinem Stil passt.">?</span></span>
                    <select name="guest_address" class="rounded-lg border-stone-200 text-sm">
                        <option value="Sie" @selected($settings->guest_address === 'Sie')>Sie (formell)</option>
                        <option value="du"  @selected($settings->guest_address === 'du')>du (informell)</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mt-4 border-t border-stone-100 pt-4">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Erinnerungen</h3>
            <div class="flex flex-wrap items-end gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="reminder_enabled" value="1" @checked($settings->reminder_enabled)> E-Mail-Erinnerung aktiv <span class="tip" tabindex="0" data-tip="Schickt deinen Gästen automatisch eine freundliche Erinnerungsmail – das reduziert No-Shows erfahrungsgemäß um 20–30 %!">?</span></label>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Stunden vorher <span class="tip" tabindex="0" data-tip="Wann soll die Erinnerungsmail ankommen? 24 Stunden vorher ist ein bewährter Wert – der Gast hat noch Zeit umzuplanen, vergisst seinen Termin aber nicht.">?</span></label>
                    <input type="number" name="reminder_hours_before" min="1" max="168" value="{{ $settings->reminder_hours_before }}" class="w-24 rounded-lg border-stone-200"></div>
                <label class="flex items-center gap-2"><input type="checkbox" name="sms_reminder_enabled" value="1" @checked($settings->sms_reminder_enabled)> SMS-Erinnerung aktiv <span class="tip" tabindex="0" data-tip="SMS werden noch häufiger gelesen als E-Mails – und das in Sekunden! Erfordert eine seven.io-Integration (Tab &#39;Integrationen&#39;).">?</span></label>
            </div>
            <p class="mt-1 text-xs text-stone-400">SMS-Erinnerungen erfordern eine konfigurierte seven.io-Integration (Tab „Integrationen") und eine Telefonnummer beim Gast.</p>
        </div>

        <div class="mt-4 border-t border-stone-100 pt-4">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-stone-400">Rückerstattung der Anzahlung</h3>
            <div class="flex flex-wrap items-end gap-4 text-sm">
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Modus <span class="tip" tabindex="0" data-tip="&#39;Aus&#39; – bei Storno wird die Anzahlung behalten. &#39;Manuell&#39; – dein Team gibt die Rückerstattung frei. &#39;Automatisch&#39; – läuft ohne Zutun. Greift nur bei Storno innerhalb der Frist.">?</span></label>
                    <select name="refund_mode" class="rounded-lg border-stone-200">
                        <option value="off"    @selected($settings->refund_mode === 'off')>Aus</option>
                        <option value="manual" @selected($settings->refund_mode === 'manual')>Manuell (Freigabe durch Personal)</option>
                        <option value="auto"   @selected($settings->refund_mode === 'auto')>Automatisch</option>
                    </select></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Erstattung in % <span class="tip" tabindex="0" data-tip="Wie viel Prozent der Anzahlung erhält der Gast zurück? 100 % = voller Betrag, 50 % = halbe Rückerstattung. Bei No-Show erfolgt nie eine Rückerstattung.">?</span></label>
                    <input type="number" name="refund_percent" min="0" max="100" value="{{ $settings->refund_percent }}" class="w-24 rounded-lg border-stone-200"></div>
                <div><label class="mb-1 block text-xs font-semibold text-stone-500">Ausführung <span class="tip" tabindex="0" data-tip="&#39;Sofort&#39; schreibt den Betrag direkt zurück. &#39;Nach Zeitplan&#39; sammelt alle Rückerstattungen und verarbeitet sie in einem Lauf – praktisch wenn viele Stornos anfallen.">?</span></label>
                    <select name="refund_processing" class="rounded-lg border-stone-200">
                        <option value="immediate" @selected($settings->refund_processing === 'immediate')>Sofort</option>
                        <option value="scheduled" @selected($settings->refund_processing === 'scheduled')>Nach Zeitplan (Sammellauf)</option>
                    </select></div>
            </div>
            <p class="mt-1 text-xs text-stone-400">Greift bei Storno innerhalb der Frist. Nach Frist und bei No-Show erfolgt keine Erstattung.</p>
        </div>

        <button class="mt-5 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Zeiten
     ═══════════════════════════════════════════════════════════════════════ --}}
<div id="tab-zeiten" class="settings-panel hidden grid gap-6 lg:grid-cols-2">

    {{-- Öffnungszeiten --}}
    <form method="POST" action="{{ route('admin.settings.opening-hours') }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        @csrf @method('PUT')
        <h2 class="mb-3 font-bold">Öffnungszeiten</h2>
        <div id="hoursContainer" class="space-y-2 text-sm">
            @foreach($openingHours as $i => $hour)
                <div class="hour-row flex items-center gap-2">
                    <select name="hours[{{ $i }}][weekday]" class="rounded-lg border-stone-200">
                        @foreach($weekdays as $wd => $name)<option value="{{ $wd }}" @selected($hour->weekday == $wd)>{{ $name }}</option>@endforeach
                    </select>
                    <input type="time" name="hours[{{ $i }}][opens_at]" value="{{ substr($hour->opens_at, 0, 5) }}" class="rounded-lg border-stone-200">
                    <span>–</span>
                    <input type="time" name="hours[{{ $i }}][closes_at]" value="{{ substr($hour->closes_at, 0, 5) }}" class="rounded-lg border-stone-200">
                    <input type="text" name="hours[{{ $i }}][service_name]" value="{{ $hour->service_name }}" placeholder="Service" title="Optionaler Name für dieses Zeitfenster, z. B. &quot;Mittagstisch&quot; oder &quot;Abendservice&quot;. Wird intern zur Übersicht angezeigt." class="w-24 rounded-lg border-stone-200">
                    <button type="button" onclick="this.closest('.hour-row').remove()" class="shrink-0 text-red-500 hover:text-red-700">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" id="addHour" class="mt-2 text-sm text-teal-700 underline">+ Zeitfenster hinzufügen</button>
        <div><button class="mt-4 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Öffnungszeiten speichern</button></div>
    </form>

    {{-- Sonderöffnungszeiten --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Sonderöffnungszeiten / Schließtage <span class="tip" tabindex="0" data-tip="Lege abweichende Öffnungszeiten oder Schließtage für bestimmte Daten fest – z. B. Feiertage, Betriebsferien oder besondere Veranstaltungstage. Diese überschreiben die regulären Öffnungszeiten.">?</span></h2>
        <form method="POST" action="{{ route('admin.settings.special-hours') }}" class="grid grid-cols-2 gap-2 text-sm">
            @csrf
            <input type="date" name="date" required title="Das genaue Datum der Sonderregelung." class="rounded-lg border-stone-200">
            <input type="text" name="label" placeholder="z. B. Feiertag" title="Ein Name damit du später weißt, warum dieser Eintrag existiert – z. B. &quot;Heiligabend&quot; oder &quot;Betriebsferien&quot;." class="rounded-lg border-stone-200">
            <input type="time" name="opens_at" title="Öffnungszeit an diesem Tag – leer lassen wenn der Tag als &quot;Geschlossen&quot; markiert wird." class="rounded-lg border-stone-200">
            <input type="time" name="closes_at" title="Schließzeit an diesem Tag." class="rounded-lg border-stone-200">
            <label class="col-span-2 flex items-center gap-1.5 text-sm"><input type="checkbox" name="closed" value="1"> Geschlossen <span class="tip" tabindex="0" data-tip="Markiert diesen Tag als komplett geschlossen. Gäste können für diesen Tag keine Buchungen vornehmen.">?</span></label>
            <button class="col-span-2 rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Speichern</button>
        </form>
        <div class="mt-3 space-y-1 text-sm">
            @foreach($specialHours as $sh)
                <div class="flex items-center justify-between rounded-lg bg-stone-50 px-3 py-2">
                    <span>
                        {{ $sh->date->format('d.m.Y') }}:
                        {{ $sh->closed ? '🔒 geschlossen' : substr($sh->opens_at, 0, 5) . '–' . substr($sh->closes_at, 0, 5) }}
                        @if($sh->label) ({{ $sh->label }}) @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Räume & Tags
     ═══════════════════════════════════════════════════════════════════════ --}}
<div id="tab-raeume" class="settings-panel hidden grid gap-6 lg:grid-cols-2">

    {{-- Räume --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Räume</h2>
        <p class="mb-3 text-xs text-stone-500">Räume anlegen, Tische dann im <a href="{{ route('admin.floorplan.index') }}" class="font-semibold text-teal-700 underline">Tischplan</a> verwalten.</p>

        @foreach($location->rooms()->where('is_active', true)->orderBy('sort_order')->get() as $room)
        <div class="mb-3 flex flex-wrap items-end gap-3 rounded-xl bg-stone-50 p-3 text-sm">
            <span class="font-semibold text-stone-800">{{ $room->name }}</span>
            @if($room->is_outdoor)<span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-700">Außen</span>@endif
            <div class="ml-auto flex items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Breite (m) <span class="tip" tabindex="0" data-tip="Echte Raummaße in Metern – optional, aber fein: Das System zeigt dann im Tischplan einen Maßstab-Ruler, damit Tische maßstabsgetreu dargestellt werden.">?</span></label>
                    <input type="number" step="0.5" min="1" max="500"
                           value="{{ $room->plan_width_m }}" placeholder="—"
                           class="room-size-m w-20 rounded-lg border-stone-200 text-sm"
                           data-room="{{ $room->id }}" data-field="plan_width_m">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Tiefe (m) <span class="tip" tabindex="0" data-tip="Die echte Tiefe (Länge) des Raums in Metern. Zusammen mit der Breite entsteht ein maßstabsgetreuer Tischplan mit Ruler.">?</span></label>
                    <input type="number" step="0.5" min="1" max="500"
                           value="{{ $room->plan_height_m }}" placeholder="—"
                           class="room-size-m w-20 rounded-lg border-stone-200 text-sm"
                           data-room="{{ $room->id }}" data-field="plan_height_m">
                </div>
                <button type="button" onclick="saveRoomSize({{ $room->id }})"
                        class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-700">
                    Speichern
                </button>
            </div>
        </div>
        @endforeach

        <form method="POST" action="{{ route('admin.settings.rooms.store') }}" class="mt-3 flex items-end gap-2 text-sm">
            @csrf
            <div class="grow"><label class="mb-1 block text-xs font-semibold text-stone-500">Neuer Raum</label>
                <input type="text" name="name" required placeholder="z. B. Wintergarten" class="w-full rounded-lg border-stone-200"></div>
            <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="is_outdoor" value="1"> Außen <span class="tip" tabindex="0" data-tip="Markiert diesen Raum als Außenbereich (Terrasse, Biergarten …). Diese Info hilft Gästen beim Wählen ihres Lieblingsplatzes.">?</span></label>
            <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
        </form>
        <p class="mt-2 text-xs text-stone-400">Breite/Tiefe in Metern ist optional – ermöglicht einen Maßstab-Ruler im Tischplan.</p>
    </div>

    {{-- Tags --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Reservierungs-Tags <span class="tip" tabindex="0" data-tip="Tags sind farbige Labels, die du Reservierungen anheften kannst – z. B. &#39;VIP&#39;, &#39;Allergiker&#39; oder &#39;Geburtstag&#39;. So erkennst du besondere Gäste im Tischplan und der Reservierungsliste auf einen Blick.">?</span></h2>
        <p class="mb-3 text-xs text-stone-500">Tags helfen beim schnellen Erkennen besonderer Reservierungen im Tischplan (VIP, Allergiker, Geburtstag, …).</p>
        <div id="settingsTagList" class="mb-3 flex flex-wrap gap-2 text-sm"></div>
        <div class="flex items-end gap-2">
            <div class="grow">
                <label class="mb-1 block text-xs font-semibold text-stone-500">Neuer Tag</label>
                <input id="settingsTagName" type="text" maxlength="40" placeholder="z. B. VIP" class="w-full rounded-lg border-stone-200 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe <span class="tip" tabindex="0" data-tip="Wähle eine markante Farbe für diesen Tag – sie erscheint als farbiger Punkt im Tischplan und in der Reservierungsliste. So erkennst du besondere Gäste sofort!">?</span></label>
                <input id="settingsTagColor" type="color" value="#6b7280" class="h-9 w-10 cursor-pointer rounded-lg border-stone-200">
            </div>
            <button id="settingsTagCreate" class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Anlegen</button>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Zahlungen
     ═══════════════════════════════════════════════════════════════════════ --}}
@if($canPayments)
<div id="tab-zahlungen" class="settings-panel hidden space-y-5">

    {{-- Stripe --}}
    @if($canInteg)
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
        <p class="mb-3 text-xs text-stone-500">Für Event-Vorauszahlungen und Reservierungs-Anzahlungen (No-Show-Schutz).</p>
        <div class="space-y-2 text-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Secret Key (sk_…) {{ $stripe ? '– leer lassen zum Beibehalten' : '' }} <span class="tip" tabindex="0" data-tip="Deinen Secret Key findest du im Stripe-Dashboard unter Developers → API keys. Beginnt mit &#39;sk_live_…&#39; – niemals öffentlich teilen! Hier wird er verschlüsselt gespeichert.">?</span></label>
                <input type="password" name="secret_key" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Webhook-Signing-Secret (whsec_…) <span class="tip" tabindex="0" data-tip="Erhältst du im Stripe-Dashboard, wenn du den Webhook-Endpunkt mit der URL unten anlegst. Er stellt sicher, dass nur echte Stripe-Ereignisse verarbeitet werden – wichtiger Sicherheitsmechanismus!">?</span></label>
                <input type="password" name="webhook_secret" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($stripe->status ?? '') === 'connected')> Online-Zahlung aktiv <span class="tip" tabindex="0" data-tip="Schaltet Stripe-Zahlungen für diesen Betrieb ein. Solange deaktiviert, werden keine Zahlungen verlangt.">?</span></label>
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
        <p class="mb-3 text-xs text-stone-500">Alternativ oder zusätzlich zu Stripe – ist beides aktiv, wählt der Gast an der Kasse.</p>
        <div class="space-y-2 text-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Client-ID {{ $paypal ? '– leer lassen zum Beibehalten' : '' }} <span class="tip" tabindex="0" data-tip="Findest du im PayPal Developer Dashboard unter Apps & Credentials. Sie identifiziert dein PayPal-Konto gegenüber dem System.">?</span></label>
                <input type="password" name="client_id" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Secret {{ $paypal ? '– leer lassen zum Beibehalten' : '' }} <span class="tip" tabindex="0" data-tip="Der geheime Schlüssel deiner PayPal-App – wird verschlüsselt gespeichert und niemals an Gäste übertragen.">?</span></label>
                <input type="password" name="secret" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Modus <span class="tip" tabindex="0" data-tip="&#39;Live&#39; für echte Zahlungen, &#39;Sandbox&#39; zum risikofreien Testen ohne echtes Geld. Denk daran, vor dem Start auf Live umzuschalten!">?</span></label>
                <select name="mode" class="w-full rounded-lg border-stone-200">
                    <option value="live"    @selected(($paypalCredentials['mode'] ?? 'live') === 'live')>Live (echte Zahlungen)</option>
                    <option value="sandbox" @selected(($paypalCredentials['mode'] ?? '') === 'sandbox')>Sandbox (Test)</option>
                </select>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($paypal->status ?? '') === 'connected')> PayPal-Zahlung aktiv <span class="tip" tabindex="0" data-tip="Schaltet PayPal als Zahlungsoption ein. Sind Stripe und PayPal beide aktiv, darf der Gast an der Kasse selbst wählen – maximale Flexibilität!">?</span></label>
        </div>
        <p class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-600">Keine Webhook-Konfiguration nötig – Zahlung wird bei Rückkehr des Gastes erfasst (Capture-on-Return).</p>
        <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>
    @endif

    {{-- Anzahlungsregeln --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Anzahlungsregeln (No-Show-Schutz)</h2>
        <p class="mb-3 text-xs text-stone-500">
            Online-Reservierungen, die eine Regel treffen, müssen vorab anzahlen.
            Der Betrag wird beim Besuch mit der Rechnung verrechnet; bei Nichterscheinen erfolgt keine Rückerstattung.
        </p>
        <form method="POST" action="{{ route('admin.settings.deposit-rules.store') }}" class="grid grid-cols-2 gap-2 text-sm">
            @csrf
            <input type="text" name="name" required placeholder="Name (z. B. Gruppen ab 6) *" title="Ein interner Name damit du die Regel wiedererkennst – z. B. &quot;Gruppen ab 6 Personen&quot; oder &quot;Abendtische ab 18 Uhr&quot;." class="col-span-2 rounded-lg border-stone-200">
            <div><label class="mb-1 block text-xs text-stone-500">Ab Personenzahl <span class="tip" tabindex="0" data-tip="Die Regel gilt nur wenn die Gruppe mindestens so groß ist. Leer lassen, wenn sie für jede Buchungsgröße gelten soll.">?</span></label>
                <input type="number" name="min_party_size" min="1" placeholder="z. B. 6" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs text-stone-500">Betrag p. P. (€) * <span class="tip" tabindex="0" data-tip="So viel Euro zahlt jede Person als Anzahlung. Beispiel: 10 € × 4 Personen = 40 € Anzahlung. Der Betrag wird beim Besuch mit der Rechnung verrechnet.">?</span></label>
                <input type="number" name="amount_per_person" required step="0.01" min="0" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs text-stone-500">Nur ab Uhrzeit <span class="tip" tabindex="0" data-tip="Die Regel gilt erst ab dieser Uhrzeit – z. B. 18:00 für Abendtische. Leer lassen wenn sie ganztags gelten soll.">?</span></label>
                <input type="time" name="from_time" class="w-full rounded-lg border-stone-200"></div>
            <div><label class="mb-1 block text-xs text-stone-500">Zahlungsfrist (Min.) <span class="tip" tabindex="0" data-tip="Wie lange hat der Gast nach der Buchung Zeit zu zahlen? Nach Ablauf wird die Buchung automatisch storniert und der Platz wieder freigegeben.">?</span></label>
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
                        <button class="text-red-500 hover:text-red-700">✕</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Integrationen
     ═══════════════════════════════════════════════════════════════════════ --}}
@if($canInteg)
<div id="tab-integrationen" class="settings-panel hidden space-y-5">

    {{-- MailWizz --}}
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
        <p class="mb-3 text-xs text-stone-500">Gäste mit Newsletter-Einwilligung werden automatisch in die MailWizz-Liste übertragen (mandantenweit).</p>
        <div class="space-y-2 text-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">API-URL <span class="tip" tabindex="0" data-tip="Die Adresse deiner MailWizz-Installation, z. B. &#39;https://mail.deinedomain.de/api&#39;. Steht in deinem MailWizz-Adminbereich unter API-Einstellungen.">?</span></label>
                <input type="url" name="api_url" required placeholder="https://news.example.com/api" value="{{ $mailwizzCredentials['api_url'] ?? '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">API-Key {{ ($mailwizzCredentials['api_key'] ?? null) ? '(gesetzt – leer lassen zum Beibehalten)' : '' }} <span class="tip" tabindex="0" data-tip="Deinen API-Schlüssel legst du in MailWizz unter Account → API an. Er erlaubt Swayy, Kontakte in deiner Liste anzulegen.">?</span></label>
                <input type="password" name="api_key" autocomplete="new-password" placeholder="{{ ($mailwizzCredentials['api_key'] ?? null) ? '••••••••' : '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Listen-UID <span class="tip" tabindex="0" data-tip="Die eindeutige ID deiner Newsletter-Liste in MailWizz – sieht aus wie &#39;abc123def&#39;. Findest du in der Listenübersicht unter &#39;Unique ID&#39;.">?</span></label>
                <input type="text" name="list_uid" required value="{{ $mailwizzCredentials['list_uid'] ?? '' }}" class="w-full rounded-lg border-stone-200">
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($mailwizz->status ?? '') === 'connected')> Synchronisierung aktiv <span class="tip" tabindex="0" data-tip="Wenn aktiv, werden Gäste mit Newsletter-Einwilligung automatisch in diese Liste übertragen. Dein Verteiler wächst ganz nebenbei!">?</span></label>
        </div>
        <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern & Verbindung testen</button>
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
                <label class="mb-1 block text-xs font-semibold text-stone-500">API-Key {{ $sms ? '– leer lassen zum Beibehalten' : '' }} <span class="tip" tabindex="0" data-tip="Deinen API-Key findest du unter app.seven.io → Einstellungen → API. Er erlaubt Swayy, SMS in deinem Namen zu versenden.">?</span></label>
                <input type="password" name="api_key" autocomplete="new-password" class="w-full rounded-lg border-stone-200">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Absender <span class="tip" tabindex="0" data-tip="Was der Empfänger als Absendernamen sieht. Max. 11 Zeichen für Namen (z. B. &#39;Salon Anna&#39;) oder eine Rufnummer. Kurze, erkennbare Namen kommen am besten an!">?</span></label>
                <input type="text" name="sender_id" maxlength="16" value="{{ $smsCredentials['sender_id'] ?? '' }}"
                       placeholder="z. B. Salon Anna" class="w-full rounded-lg border-stone-200">
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" @checked(($sms->status ?? '') === 'connected')> SMS-Versand aktiv <span class="tip" tabindex="0" data-tip="Schaltet den SMS-Versand über seven.io ein. Die eigentliche Erinnerung aktivierst du zusätzlich unter Buchungsregeln → Erinnerungen.">?</span></label>
        </div>
        <p class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-600">
            SMS-Erinnerung muss zusätzlich pro Standort unter „Buchungsregeln → Erinnerungen" aktiviert werden.
        </p>
        <button class="mt-3 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white">Speichern</button>
    </form>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════════
     TAB: Website-Widget
     ═══════════════════════════════════════════════════════════════════════ --}}
<div id="tab-widget" class="settings-panel hidden">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Website-Widget</h2>
        <p class="mb-4 text-xs text-stone-500">Binde die Buchungsfunktion direkt auf deiner Website ein. Die Farbe aus „Allgemein → Markenfarbe" wird automatisch verwendet.</p>

        {{-- Sub-Tabs --}}
        <div class="mb-4 flex gap-1 rounded-xl bg-stone-100 p-1 text-sm" role="tablist">
            <button class="widget-tab flex-1 rounded-lg bg-white px-3 py-1.5 font-semibold shadow-sm" data-tab="popup" role="tab" aria-selected="true">Popup-Button</button>
            <button class="widget-tab flex-1 rounded-lg px-3 py-1.5 font-medium text-stone-500" data-tab="inline" role="tab" aria-selected="false">Eingebettet</button>
            <button class="widget-tab flex-1 rounded-lg px-3 py-1.5 font-medium text-stone-500" data-tab="link" role="tab" aria-selected="false">Direktlink</button>
        </div>

        {{-- Popup --}}
        <div id="widgetTab-popup" role="tabpanel">
            <p class="mb-3 text-xs text-stone-500">Ein Klick öffnet das Buchungsformular als Overlay. Ideal für alle Websites.</p>
            <div class="mb-3 grid grid-cols-2 gap-3">
                <div>
                    <label for="wLabel" class="mb-1 block text-xs font-semibold">Button-Text <span class="tip" tabindex="0" data-tip="Was auf deinem Buchungsbutton steht – z. B. &#39;Tisch reservieren&#39;, &#39;Termin buchen&#39; oder einfach &#39;Jetzt buchen&#39;. Kurz und einladend ist am besten!">?</span></label>
                    <input id="wLabel" value="Jetzt reservieren" class="w-full rounded-xl border-2 border-stone-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="wColor" class="mb-1 block text-xs font-semibold">Farbe <span class="tip" tabindex="0" data-tip="Die Hintergrundfarbe deines Buchungsbuttons. Passt sich automatisch an deine Markenfarbe an – hier kannst du aber auch etwas Eigenes wählen.">?</span></label>
                    <input type="color" id="wColor" value="{{ $tenant->brand_primary_color ?: '#0d9488' }}" class="h-10 w-full cursor-pointer rounded-xl border-2 border-stone-200">
                </div>
            </div>
            <label class="mb-3 flex items-center gap-2 text-sm">
                <input type="checkbox" id="wFloat" class="rounded"> Floating-Button <span class="tip" tabindex="0" data-tip="Der Button klebt dann fest am rechten unteren Rand der Seite – immer sichtbar, egal wie weit dein Besucher scrollt. Sehr effektiv für hohe Konversionsraten!">?</span>
            </label>
            <div class="relative">
                <pre id="wSnippetPopup" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
                <button onclick="swayyWidgetCopy('wSnippetPopup',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
            </div>
            <p class="mt-3 text-xs text-stone-400">Vor dem schließenden <code>&lt;/body&gt;</code>-Tag einfügen. Funktioniert mit WordPress, Squarespace, Webflow und jeder anderen Website.</p>
        </div>

        {{-- Inline --}}
        <div id="widgetTab-inline" class="hidden" role="tabpanel">
            <p class="mb-3 text-xs text-stone-500">Das Buchungsformular erscheint direkt auf der Seite als eingebettetes Formular. Ideal für eine eigene Reservierungs-Unterseite.</p>
            <div class="relative">
                <pre id="wSnippetInline" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
                <button onclick="swayyWidgetCopy('wSnippetInline',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
            </div>
            <p class="mt-3 text-xs text-stone-400">Das <code>div#swayy-widget</code> zeigt, wo das Formular erscheint. Das Script lädt es automatisch als responsive iFrame.</p>
        </div>

        {{-- Link --}}
        <div id="widgetTab-link" class="hidden" role="tabpanel">
            <p class="mb-3 text-xs text-stone-500">Ein einfacher HTML-Button-Link zur Buchungsseite – kein JavaScript, maximale Kompatibilität.</p>
            <div class="mb-3 grid grid-cols-2 gap-3">
                <div>
                    <label for="wLinkLabel" class="mb-1 block text-xs font-semibold">Button-Text <span class="tip" tabindex="0" data-tip="Was auf dem Buchungslink-Button steht. Kein JavaScript nötig – maximale Kompatibilität mit jeder Website.">?</span></label>
                    <input id="wLinkLabel" value="Jetzt reservieren" class="w-full rounded-xl border-2 border-stone-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="wLinkColor" class="mb-1 block text-xs font-semibold">Farbe <span class="tip" tabindex="0" data-tip="Die Hintergrundfarbe des Link-Buttons. Wird nicht von der Markenfarbe beeinflusst, da das ein statischer HTML-Link ist.">?</span></label>
                    <input type="color" id="wLinkColor" value="{{ $tenant->brand_primary_color ?: '#0d9488' }}" class="h-10 w-full cursor-pointer rounded-xl border-2 border-stone-200">
                </div>
            </div>
            <div class="relative">
                <pre id="wSnippetLink" class="overflow-x-auto rounded-xl bg-stone-50 p-3 pr-20 text-xs leading-relaxed text-stone-700 ring-1 ring-stone-200"></pre>
                <button onclick="swayyWidgetCopy('wSnippetLink',this)" class="absolute right-2 top-2 rounded-lg bg-stone-800 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
            </div>
        </div>
    </div>
</div>

{{-- ─── Scripts ─────────────────────────────────────────────────────────── --}}
<script>
// ── Tab-Navigation ─────────────────────────────────────────────────────────
(function () {
    var TABS = ['allgemein','buchungsregeln','zeiten','raeume','zahlungen','integrationen','widget'];
    var panels = {};
    var buttons = {};
    TABS.forEach(function (id) {
        panels[id]  = document.getElementById('tab-' + id);
        buttons[id] = document.querySelector('[data-tab="' + id + '"]');
    });

    function activateTab(id) {
        if (!panels[id]) {
            id = 'allgemein';
        }
        TABS.forEach(function (t) {
            if (panels[t]) panels[t].classList.toggle('hidden', t !== id);
            if (buttons[t]) {
                buttons[t].setAttribute('aria-selected', t === id ? 'true' : 'false');
                if (t === id) {
                    buttons[t].classList.add('bg-white', 'shadow-sm', 'text-stone-900');
                    buttons[t].classList.remove('text-stone-500');
                } else {
                    buttons[t].classList.remove('bg-white', 'shadow-sm', 'text-stone-900');
                    buttons[t].classList.add('text-stone-500');
                }
            }
        });
        history.replaceState(null, '', '#' + id);
    }

    document.querySelectorAll('.settings-tab').forEach(function (btn) {
        btn.addEventListener('click', function () { activateTab(btn.dataset.tab); });
    });

    var hash = (location.hash || '').replace('#', '');
    activateTab(TABS.includes(hash) ? hash : 'allgemein');
})();
</script>

<script>
// ── Öffnungszeiten: Zeile hinzufügen ──────────────────────────────────────
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
</script>

<script>
// ── Markenfarbe-Vorschau ──────────────────────────────────────────────────
(function () {
    var colorInput   = document.getElementById('brandColor');
    var previewBtn   = document.querySelector('#brandColorPreview span');
    if (!colorInput || !previewBtn) return;
    colorInput.addEventListener('input', function () {
        previewBtn.style.background = colorInput.value;
        // Keep widget pickers in sync
        var wc = document.getElementById('wColor');
        var wl = document.getElementById('wLinkColor');
        if (wc) { wc.value = colorInput.value; wc.dispatchEvent(new Event('input')); }
        if (wl) { wl.value = colorInput.value; wl.dispatchEvent(new Event('input')); }
    });
})();
</script>

<script>
// ── AJAX-Form-Interceptor ─────────────────────────────────────────────────
(function () {
    const csrf  = @json(csrf_token());
    const toast = document.getElementById('settingsToast');
    const scrollKey = 'sw_settings_scroll';

    function showToast(msg, isErr) {
        toast.textContent = msg;
        toast.className = [
            'pointer-events-none fixed bottom-6 right-6 z-50 max-w-sm rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300',
            isErr ? 'bg-red-600 text-white' : 'bg-stone-900 text-white',
        ].join(' ');
        toast.style.opacity  = '1';
        toast.style.transform = 'translateY(0)';
        clearTimeout(toast._t);
        toast._t = setTimeout(() => {
            toast.style.opacity   = '0';
            toast.style.transform = 'translateY(8px)';
        }, 3200);
    }

    // Scroll restore after reload
    const saved = sessionStorage.getItem(scrollKey);
    if (saved !== null) { window.scrollTo(0, parseInt(saved, 10)); sessionStorage.removeItem(scrollKey); }

    document.querySelectorAll('form').forEach(form => {
        if (form.querySelector('input[name="_method"][value="DELETE"]')) return;

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const btn  = form.querySelector('[type=submit]');
            const orig = btn?.textContent;
            if (btn) { btn.disabled = true; btn.textContent = '…'; }

            try {
                const isMultipart = form.enctype === 'multipart/form-data';
                const body = isMultipart ? new FormData(form) : new URLSearchParams(new FormData(form));
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

                    if (json.logo_url) {
                        const img  = document.getElementById('logoPreviewImg');
                        const wrap = document.getElementById('logoPreviewWrap');
                        const ph   = document.getElementById('logoPlaceholder');
                        if (img) {
                            img.src = json.logo_url + '?t=' + Date.now();
                        } else if (wrap) {
                            wrap.innerHTML = `<img id="logoPreviewImg" src="${json.logo_url}?t=${Date.now()}" alt="Logo" class="h-full w-full object-contain">`;
                        }
                        if (ph) ph.remove();
                        const fi = form.querySelector('input[type=file]');
                        if (fi) fi.value = '';
                        return;
                    }

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
    const csrf      = @json(csrf_token());
    const indexUrl  = @json(route('admin.tags.index'));
    const storeUrl  = @json(route('admin.tags.store'));
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
        const name  = document.getElementById('settingsTagName').value.trim();
        const color = document.getElementById('settingsTagColor').value;
        if (!name) return;
        const res = await fetch(storeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ name, color }),
        });
        if (res.ok) {
            const tag = await res.json();
            if (!tags.find(t => t.id === tag.id)) tags.push(tag); else tags = tags.map(t => t.id === tag.id ? tag : t);
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
// ── Website-Widget (Snippets + Sub-Tabs) ───────────────────────────────────
(function () {
    var popupSrc   = @json($widgetPopupSrc);
    var embedSrc   = @json($widgetEmbedSrc);
    var bookingUrl = @json($bookingUrl);

    function updateSnippets() {
        var label     = document.getElementById('wLabel')?.value     || 'Jetzt reservieren';
        var color     = document.getElementById('wColor')?.value     || '#0d9488';
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

    ['wLabel','wColor','wFloat','wLinkLabel','wLinkColor'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', updateSnippets); el.addEventListener('change', updateSnippets); }
    });

    updateSnippets();
})();

// ── Raumgröße in Metern ────────────────────────────────────────────────────
async function saveRoomSize(roomId) {
    const inputs = document.querySelectorAll('.room-size-m[data-room="' + roomId + '"]');
    const body   = {};
    inputs.forEach(inp => {
        const v = parseFloat(inp.value);
        body[inp.dataset.field] = isNaN(v) ? null : v;
    });
    const csrf = document.querySelector('input[name=_token]')?.value || '';
    const res  = await fetch('/admin/floorplan/rooms/' + roomId + '/size', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        body: JSON.stringify(body),
    });
    const toast = document.getElementById('settingsToast');
    if (toast) {
        toast.textContent  = res.ok ? 'Raumgröße gespeichert.' : 'Fehler beim Speichern.';
        toast.className    = 'pointer-events-none fixed bottom-6 right-6 z-50 max-w-sm rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300 '
            + (res.ok ? 'bg-stone-900 text-white' : 'bg-red-600 text-white');
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateY(8px)'; }, 2500);
    }
}

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

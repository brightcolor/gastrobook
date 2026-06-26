<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Einrichtung – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-stone-50 to-teal-50/30">
@php
    $isSalon  = $tenant->isSalon();
    $typeName = $isSalon ? 'Salon / Dienstleister' : 'Restaurant / Café';
    $weekdays = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
    $csrf     = csrf_token();

    $steps = [
        ['id'=>'type',    'label'=>'Betriebstyp',          'icon'=>'🏷',  'optional'=>false],
        ['id'=>'hours',   'label'=>'Öffnungszeiten',        'icon'=>'🕐',  'optional'=>false],
        ['id'=>'setup',   'label'=>$isSalon?'Team & Leistungen':'Räume & Tische', 'icon'=>$isSalon?'💇':'🪑', 'optional'=>false],
        ['id'=>'rules',   'label'=>'Buchungsregeln',         'icon'=>'⚙️',  'optional'=>true],
        ['id'=>'branding','label'=>'Logo & Farbe',           'icon'=>'🎨',  'optional'=>true],
        ['id'=>'done',    'label'=>'Fertig',                 'icon'=>'✅',  'optional'=>false],
    ];
@endphp

<div class="flex min-h-screen">

    {{-- ── Sidebar ─────────────────────────────────────────────────────── --}}
    <aside class="hidden w-64 shrink-0 flex-col bg-white shadow-sm lg:flex">
        <div class="border-b border-stone-100 px-6 py-5">
            <span class="text-xl font-black tracking-tight text-stone-900">Swayy</span>
            <p class="mt-0.5 text-xs text-stone-400">Einrichtungsassistent</p>
        </div>
        <nav class="flex-1 px-4 py-6">
            <p class="mb-3 px-2 text-xs font-bold uppercase tracking-widest text-stone-400">Schritte</p>
            <ol class="space-y-1" id="sidebarSteps">
                @foreach($steps as $i => $step)
                <li>
                    <button data-goto="{{ $i }}"
                            class="sidebar-step w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition"
                            aria-current="false">
                        <span class="step-icon flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold transition">
                            {{ $i + 1 }}
                        </span>
                        <span class="flex-1 leading-tight">
                            {{ $step['label'] }}
                            @if($step['optional'])
                                <span class="block text-[10px] font-normal text-stone-400">Optional</span>
                            @endif
                        </span>
                        <span class="step-check hidden text-emerald-500 text-sm">✓</span>
                    </button>
                </li>
                @endforeach
            </ol>
        </nav>
        <div class="border-t border-stone-100 px-6 py-4">
            <p class="text-xs text-stone-400">{{ $tenant->name }}</p>
            <p class="text-xs text-stone-400">{{ $location->name }}</p>
        </div>
    </aside>

    {{-- ── Main ────────────────────────────────────────────────────────── --}}
    <main class="flex flex-1 flex-col">

        {{-- Mobile progress bar --}}
        <div class="h-1 bg-stone-100 lg:hidden">
            <div id="progressBar" class="h-full bg-teal-500 transition-all duration-500" style="width:0%"></div>
        </div>

        <div class="flex flex-1 items-start justify-center p-6 pt-10 lg:p-16">
            <div class="w-full max-w-2xl">

                {{-- Error toast --}}
                <div id="onboardingError" class="mb-5 hidden rounded-xl bg-red-50 px-5 py-3 text-sm font-semibold text-red-700 ring-1 ring-red-200"></div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 0: Betriebstyp                                 --}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step" data-step="0">
                    <h1 class="mb-1 text-2xl font-black">Willkommen bei Swayy!</h1>
                    <p class="mb-8 text-stone-500">Lass uns deinen Betrieb in wenigen Minuten einrichten. Zuerst: Welchen Betrieb führst du?</p>

                    <div class="grid gap-4 sm:grid-cols-2" id="typeCards">
                        @foreach(\App\Enums\TenantType::cases() as $type)
                        <label class="type-card relative flex cursor-pointer flex-col gap-3 rounded-2xl border-2 p-5 transition
                            {{ $tenant->type === $type ? 'border-teal-600 bg-teal-50' : 'border-stone-200 bg-white hover:border-stone-400' }}">
                            <input type="radio" name="type" value="{{ $type->value }}"
                                   @checked($tenant->type === $type) class="sr-only">
                            <span class="text-3xl">{{ $type->icon() }}</span>
                            <div>
                                <div class="font-bold text-stone-900">{{ $type->label() }}</div>
                                <div class="mt-0.5 text-xs text-stone-500">
                                    @if($type->value === 'restaurant') Tischreservierungen, Laufkunden, Sitzplan
                                    @else Terminbuchungen, Mitarbeiter, Leistungen
                                    @endif
                                </div>
                            </div>
                            <span class="type-check absolute right-3 top-3 hidden text-teal-600 text-lg">✓</span>
                        </label>
                        @endforeach
                    </div>

                    <div class="mt-8 flex justify-end">
                        <button class="ob-next rounded-2xl bg-stone-900 px-8 py-3 font-bold text-white hover:bg-stone-700" data-step="0">
                            Weiter →
                        </button>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 1: Öffnungszeiten (PFLICHT)                    --}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step hidden" data-step="1">
                    <h1 class="mb-1 text-2xl font-black">Öffnungszeiten</h1>
                    <p class="mb-6 text-stone-500">Ohne Öffnungszeiten können keine Buchungsslots angeboten werden. Füge mindestens einen Tag hinzu.</p>

                    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                        <div id="obHoursContainer" class="space-y-2 text-sm">
                            <div class="hour-row flex flex-wrap items-center gap-2">
                                <select name="hours[0][weekday]" class="rounded-lg border-stone-200">
                                    @foreach($weekdays as $wd => $name)
                                        <option value="{{ $wd }}" @selected($wd === 0)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                <input type="time" name="hours[0][opens_at]"  value="11:00" class="rounded-lg border-stone-200">
                                <span class="text-stone-400">–</span>
                                <input type="time" name="hours[0][closes_at]" value="22:00" class="rounded-lg border-stone-200">
                                <input type="text"  name="hours[0][service_name]" placeholder="Service (optional)" class="w-28 rounded-lg border-stone-200">
                                <button type="button" onclick="this.closest('.hour-row').remove()" class="text-red-400 hover:text-red-600">✕</button>
                            </div>
                        </div>
                        <button type="button" id="obAddHour" class="mt-3 text-sm text-teal-700 underline">+ Weiteren Tag hinzufügen</button>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <button class="ob-back rounded-xl border border-stone-200 bg-white px-6 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-50" data-step="1">← Zurück</button>
                        <button class="ob-next rounded-2xl bg-stone-900 px-8 py-3 font-bold text-white hover:bg-stone-700" data-step="1">
                            Speichern & Weiter →
                        </button>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 2: Räume & Tische / Team & Leistungen (PFLICHT)--}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step hidden" data-step="2">
                    @if(!$isSalon)
                    {{-- RESTAURANT --}}
                    <h1 class="mb-1 text-2xl font-black">Erster Raum & Tisch</h1>
                    <p class="mb-6 text-stone-500">Lege mindestens einen Raum und einen Tisch an. Du kannst später weitere im Tischplan hinzufügen.</p>

                    <div class="space-y-4">
                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                            <h2 class="mb-3 font-bold text-stone-800">Raum anlegen</h2>
                            <div class="flex gap-3 text-sm">
                                <div class="grow">
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Raumname *</label>
                                    <input id="obRoomName" type="text" placeholder="z. B. Innenbereich" required class="w-full rounded-lg border-stone-200">
                                </div>
                                <div class="flex items-end pb-0.5">
                                    <label class="flex items-center gap-1.5 text-xs whitespace-nowrap">
                                        <input id="obRoomOutdoor" type="checkbox" value="1"> Außen
                                    </label>
                                </div>
                            </div>
                            <p id="obRoomStatus" class="mt-2 text-xs text-emerald-600 hidden"></p>
                        </div>

                        <div id="obTableBox" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100 opacity-40 pointer-events-none transition">
                            <h2 class="mb-3 font-bold text-stone-800">Ersten Tisch anlegen</h2>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="col-span-2">
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Tischname *</label>
                                    <input id="obTableName" type="text" placeholder="z. B. Tisch 1" class="w-full rounded-lg border-stone-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Min. Personen *</label>
                                    <input id="obTableMin" type="number" value="1" min="1" max="50" class="w-full rounded-lg border-stone-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Max. Personen *</label>
                                    <input id="obTableMax" type="number" value="4" min="1" max="50" class="w-full rounded-lg border-stone-200">
                                </div>
                            </div>
                        </div>
                    </div>

                    @else
                    {{-- SALON --}}
                    <h1 class="mb-1 text-2xl font-black">Erster Mitarbeiter & Leistung</h1>
                    <p class="mb-6 text-stone-500">Lege mindestens einen Mitarbeiter und eine Leistung an – dann können Kunden Termine buchen.</p>

                    <div class="space-y-4">
                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                            <h2 class="mb-3 font-bold text-stone-800">Mitarbeiter anlegen</h2>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="col-span-2">
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                                    <input id="obStaffName" type="text" placeholder="z. B. Anna Müller" required class="w-full rounded-lg border-stone-200">
                                </div>
                                <div class="col-span-2">
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe (für Kalender)</label>
                                    <input id="obStaffColor" type="color" value="#0d9488" class="h-9 w-14 cursor-pointer rounded-lg border-stone-200">
                                </div>
                            </div>
                            <p id="obStaffStatus" class="mt-2 text-xs text-emerald-600 hidden"></p>
                        </div>

                        <div id="obServiceBox" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100 opacity-40 pointer-events-none transition">
                            <h2 class="mb-3 font-bold text-stone-800">Erste Leistung anlegen</h2>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="col-span-2">
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Leistungsname *</label>
                                    <input id="obServiceName" type="text" placeholder="z. B. Haarschnitt Herren" class="w-full rounded-lg border-stone-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Dauer (Min.) *</label>
                                    <input id="obServiceDuration" type="number" value="30" min="5" max="480" class="w-full rounded-lg border-stone-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">Preis (€)</label>
                                    <input id="obServicePrice" type="number" value="0" step="0.01" min="0" class="w-full rounded-lg border-stone-200">
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="mt-6 flex items-center justify-between">
                        <button class="ob-back rounded-xl border border-stone-200 bg-white px-6 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-50" data-step="2">← Zurück</button>
                        <button class="ob-next rounded-2xl bg-stone-900 px-8 py-3 font-bold text-white hover:bg-stone-700" data-step="2">
                            Speichern & Weiter →
                        </button>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 3: Buchungsregeln (OPTIONAL)                   --}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step hidden" data-step="3">
                    <h1 class="mb-1 text-2xl font-black">Buchungsregeln</h1>
                    <p class="mb-1 text-stone-500">Diese Werte kannst du jederzeit in den Einstellungen anpassen.</p>
                    <p class="mb-6 text-xs font-semibold text-teal-600">Optional – du kannst diesen Schritt überspringen.</p>

                    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Min. Personen online</label>
                                <input name="min_party_online" id="obMinParty" type="number" value="1" min="1" max="50" class="w-full rounded-lg border-stone-200">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Max. Personen online</label>
                                <input name="max_party_online" id="obMaxParty" type="number" value="10" min="1" max="100" class="w-full rounded-lg border-stone-200">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Mindestvorlauf (Min.)</label>
                                <input name="min_lead_minutes" id="obMinLead" type="number" value="60" min="0" class="w-full rounded-lg border-stone-200">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Max. Vorausbuchung (Tage)</label>
                                <input name="max_advance_days" id="obMaxAdvance" type="number" value="60" min="1" max="730" class="w-full rounded-lg border-stone-200">
                            </div>
                        </div>
                        <div class="mt-4 space-y-2 text-sm">
                            <label class="flex items-center gap-2"><input id="obAutoConfirm" type="checkbox" checked> Online-Reservierungen automatisch bestätigen</label>
                            <label class="flex items-center gap-2"><input id="obEmailConfirm" type="checkbox"> E-Mail-Bestätigung durch Gast verlangen</label>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <button class="ob-back rounded-xl border border-stone-200 bg-white px-6 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-50" data-step="3">← Zurück</button>
                        <div class="flex gap-3">
                            <button class="ob-skip rounded-xl border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-500 hover:bg-stone-50" data-step="3">Überspringen</button>
                            <button class="ob-next rounded-2xl bg-stone-900 px-8 py-3 font-bold text-white hover:bg-stone-700" data-step="3">Speichern & Weiter →</button>
                        </div>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 4: Logo & Farbe (OPTIONAL)                     --}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step hidden" data-step="4">
                    <h1 class="mb-1 text-2xl font-black">Logo & Markenfarbe</h1>
                    <p class="mb-1 text-stone-500">Erscheint auf der öffentlichen Buchungsseite und im Website-Widget.</p>
                    <p class="mb-6 text-xs font-semibold text-teal-600">Optional – du kannst diesen Schritt überspringen.</p>

                    <div class="space-y-4">
                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                            <h2 class="mb-3 font-bold text-stone-800">Logo</h2>
                            <div class="flex flex-wrap items-center gap-4">
                                <div id="obLogoPreview" class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50">
                                    <span class="text-2xl text-stone-300">🍽</span>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-stone-500">PNG, JPG oder WebP, max. 3 MB</label>
                                    <input id="obLogo" type="file" accept="image/png,image/jpeg,image/webp"
                                           class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-900 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-white">
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                            <h2 class="mb-3 font-bold text-stone-800">Markenfarbe</h2>
                            <div class="flex items-center gap-4">
                                <input id="obBrandColor" type="color" value="{{ $tenant->brand_primary_color ?: '#0d9488' }}"
                                       class="h-10 w-16 cursor-pointer rounded-xl border-2 border-stone-200">
                                <span id="obColorPreview" class="rounded-xl px-4 py-2 text-sm font-semibold text-white"
                                      style="background:{{ $tenant->brand_primary_color ?: '#0d9488' }}">
                                    Jetzt reservieren
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <button class="ob-back rounded-xl border border-stone-200 bg-white px-6 py-2.5 text-sm font-semibold text-stone-700 hover:bg-stone-50" data-step="4">← Zurück</button>
                        <div class="flex gap-3">
                            <button class="ob-skip rounded-xl border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-500 hover:bg-stone-50" data-step="4">Überspringen</button>
                            <button class="ob-next rounded-2xl bg-stone-900 px-8 py-3 font-bold text-white hover:bg-stone-700" data-step="4">Speichern & Weiter →</button>
                        </div>
                    </div>
                </div>

                {{-- ════════════════════════════════════════════════════ --}}
                {{-- STEP 5: Fertig                                       --}}
                {{-- ════════════════════════════════════════════════════ --}}
                <div class="ob-step hidden text-center" data-step="5">
                    <div class="mb-6 text-6xl">🎉</div>
                    <h1 class="mb-2 text-3xl font-black">Alles bereit!</h1>
                    <p class="mb-8 text-stone-500">
                        Dein Betrieb ist eingerichtet und Gäste können ab sofort online buchen.
                        Im Dashboard findest du alle Reservierungen auf einen Blick.
                    </p>

                    <div class="mb-8 rounded-2xl bg-white p-5 text-left shadow-sm ring-1 ring-stone-100">
                        <h2 class="mb-3 font-bold text-stone-700">Buchungsseite teilen</h2>
                        <div class="flex items-center gap-2">
                            <code id="obBookingUrl" class="flex-1 overflow-x-auto rounded-lg bg-stone-50 px-3 py-2 text-xs text-teal-700 ring-1 ring-stone-200">
                                {{ route('booking.landing', $tenant->slug) }}
                            </code>
                            <button onclick="navigator.clipboard.writeText(document.getElementById('obBookingUrl').textContent.trim()).then(()=>{this.textContent='✓';setTimeout(()=>{this.textContent='Kopieren'},1500)})"
                                    class="shrink-0 rounded-lg bg-stone-800 px-3 py-2 text-xs font-semibold text-white hover:bg-stone-700">Kopieren</button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-3 text-sm">
                            <a href="{{ route('booking.landing', $tenant->slug) }}" target="_blank"
                               class="rounded-lg bg-stone-100 px-3 py-1.5 font-semibold text-stone-700 hover:bg-stone-200">Buchungsseite ansehen ↗</a>
                            <a href="{{ route('admin.settings.index') }}#widget"
                               class="rounded-lg bg-teal-50 px-3 py-1.5 font-semibold text-teal-700 hover:bg-teal-100">Website-Widget einrichten ↗</a>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.onboarding.complete') }}">
                        @csrf
                        <button class="rounded-2xl bg-teal-600 px-10 py-3.5 text-base font-bold text-white hover:bg-teal-700">
                            Zum Dashboard →
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    const CSRF    = @json($csrf);
    const IS_SALON = @json($isSalon);
    const STEPS_COUNT = 6;

    // ── Routes ──────────────────────────────────────────────────────────────
    const ROUTE_TYPE    = @json(route('admin.settings.tenant-type'));
    const ROUTE_HOURS   = @json(route('admin.settings.opening-hours'));
    const ROUTE_ROOMS   = @json(route('admin.settings.rooms.store'));
    const ROUTE_TABLES  = @json(route('admin.settings.tables.store'));
    const ROUTE_STAFF   = @json(route('admin.staff.store'));
    const ROUTE_SERVICE = @json(route('admin.services.store'));
    const ROUTE_RULES   = @json(route('admin.settings.booking-rules'));
    const ROUTE_BRANDING= @json(route('admin.settings.branding'));
    const ROUTE_LOGO    = @json(route('admin.settings.logo.upload'));

    // ── State ────────────────────────────────────────────────────────────────
    let currentStep = 0;
    const completed = new Set();
    let createdRoomId = null;
    let createdStaffId = null;

    // ── DOM helpers ──────────────────────────────────────────────────────────
    const $steps      = Array.from(document.querySelectorAll('.ob-step'));
    const $sidebar    = Array.from(document.querySelectorAll('.sidebar-step'));
    const $bar        = document.getElementById('progressBar');
    const $err        = document.getElementById('onboardingError');

    function showError(msg) {
        $err.textContent = msg;
        $err.classList.remove('hidden');
        $err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function clearError() { $err.classList.add('hidden'); }

    function goTo(n) {
        currentStep = n;
        $steps.forEach((el, i) => el.classList.toggle('hidden', i !== n));
        $sidebar.forEach((btn, i) => {
            const isActive = i === n;
            btn.setAttribute('aria-current', isActive ? 'step' : 'false');
            btn.classList.toggle('bg-teal-50',  isActive);
            btn.classList.toggle('text-teal-800', isActive);
            btn.classList.toggle('font-semibold', isActive);
            const icon = btn.querySelector('.step-icon');
            if (icon) {
                icon.classList.toggle('border-teal-600',  isActive);
                icon.classList.toggle('bg-teal-600',       isActive);
                icon.classList.toggle('text-white',        isActive);
                icon.classList.toggle('border-stone-200', !isActive && !completed.has(i));
            }
            btn.querySelector('.step-check')?.classList.toggle('hidden', !completed.has(i));
        });
        $bar.style.width = ((n / (STEPS_COUNT - 1)) * 100) + '%';
        clearError();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function markDone(n) { completed.add(n); }

    // ── Fetch helper ─────────────────────────────────────────────────────────
    async function post(url, body, multipart) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF };
        let bodyData;
        if (multipart) {
            bodyData = body; // FormData
        } else {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            bodyData = new URLSearchParams(body);
        }
        const res = await fetch(url, { method: 'POST', headers, body: bodyData });
        let json = {};
        try { json = await res.json(); } catch {}
        return { ok: res.ok, json };
    }
    async function put(url, body) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF,
                          'Content-Type': 'application/x-www-form-urlencoded' };
        const res = await fetch(url, { method: 'PUT', headers, body: new URLSearchParams(body) });
        let json = {};
        try { json = await res.json(); } catch {}
        return { ok: res.ok, json };
    }
    function errMsg(json) {
        if (json.errors) return Object.values(json.errors).flat().join(' · ');
        return json.message || 'Fehler beim Speichern.';
    }

    // ── Step 0: Betriebstyp ──────────────────────────────────────────────────
    // Type cards - visual selection
    document.querySelectorAll('.type-card input[type=radio]').forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.type-card').forEach(card => {
                const isSelected = card.querySelector('input').checked;
                card.classList.toggle('border-teal-600', isSelected);
                card.classList.toggle('bg-teal-50', isSelected);
                card.classList.toggle('border-stone-200', !isSelected);
                card.querySelector('.type-check').classList.toggle('hidden', !isSelected);
            });
        });
    });
    // init check marks
    document.querySelectorAll('.type-card').forEach(card => {
        const isSelected = card.querySelector('input').checked;
        card.querySelector('.type-check').classList.toggle('hidden', !isSelected);
    });

    async function saveStep0(btn) {
        const selected = document.querySelector('input[name="type"]:checked');
        if (!selected) { showError('Bitte wähle einen Betriebstyp.'); return; }
        btn.disabled = true; btn.textContent = '…';
        const { ok, json } = await put(ROUTE_TYPE, { type: selected.value, _method: 'PUT' });
        btn.disabled = false; btn.textContent = 'Weiter →';
        if (!ok) { showError(errMsg(json)); return; }
        markDone(0);
        // Reload if type changed (the page will re-init at step 1 via hash)
        if (json.reload) {
            sessionStorage.setItem('ob_step', '1');
            location.reload();
            return;
        }
        goTo(1);
    }

    // ── Step 1: Öffnungszeiten ────────────────────────────────────────────────
    document.getElementById('obAddHour')?.addEventListener('click', () => {
        const container = document.getElementById('obHoursContainer');
        const i = Date.now();
        const nextDay = Math.min(container.querySelectorAll('.hour-row').length, 6);
        const div = document.createElement('div');
        div.className = 'hour-row flex flex-wrap items-center gap-2';
        div.innerHTML = `
            <select name="hours[${i}][weekday]" class="rounded-lg border-stone-200">
                ${['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'].map((d, idx) =>
                    `<option value="${idx}" ${idx === nextDay ? 'selected' : ''}>${d}</option>`).join('')}
            </select>
            <input type="time" name="hours[${i}][opens_at]" value="11:00" class="rounded-lg border-stone-200">
            <span class="text-stone-400">–</span>
            <input type="time" name="hours[${i}][closes_at]" value="22:00" class="rounded-lg border-stone-200">
            <input type="text" name="hours[${i}][service_name]" placeholder="Service (optional)" class="w-28 rounded-lg border-stone-200">
            <button type="button" onclick="this.closest('.hour-row').remove()" class="text-red-400 hover:text-red-600">✕</button>`;
        container.appendChild(div);
    });

    async function saveStep1(btn) {
        const rows = document.querySelectorAll('#obHoursContainer .hour-row');
        if (!rows.length) { showError('Bitte füge mindestens ein Zeitfenster hinzu.'); return; }

        const body = { _method: 'PUT' };
        rows.forEach((row, idx) => {
            row.querySelectorAll('[name]').forEach(el => {
                // Normalize key: hours[timestamp][field] → hours[idx][field]
                const key = el.name.replace(/hours\[[^\]]+\]/, `hours[${idx}]`);
                body[key] = el.value;
            });
        });

        btn.disabled = true; btn.textContent = '…';
        const { ok, json } = await put(ROUTE_HOURS, body);
        btn.disabled = false; btn.textContent = 'Speichern & Weiter →';
        if (!ok) { showError(errMsg(json)); return; }
        markDone(1);
        goTo(2);
    }

    // ── Step 2: Restaurant – Raum + Tisch ─────────────────────────────────────
    async function saveStep2Restaurant(btn) {
        const roomName    = document.getElementById('obRoomName')?.value?.trim();
        const roomOutdoor = document.getElementById('obRoomOutdoor')?.checked;

        if (!roomName) { showError('Bitte gib einen Raumnamen ein.'); return; }

        btn.disabled = true; btn.textContent = '…';

        // 1) Create room
        if (!createdRoomId) {
            const { ok, json } = await post(ROUTE_ROOMS, { name: roomName, is_outdoor: roomOutdoor ? '1' : '' });
            if (!ok) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError(errMsg(json)); return; }
            // Derive room ID from redirect or assume success
            createdRoomId = json.room_id ?? json.id ?? 1;

            const status = document.getElementById('obRoomStatus');
            if (status) { status.textContent = 'Raum angelegt ✓'; status.classList.remove('hidden'); }

            // Unlock table box
            const tableBox = document.getElementById('obTableBox');
            if (tableBox) { tableBox.classList.remove('opacity-40', 'pointer-events-none'); }
        }

        // 2) Create table
        const tableName = document.getElementById('obTableName')?.value?.trim();
        const tableMin  = document.getElementById('obTableMin')?.value || '1';
        const tableMax  = document.getElementById('obTableMax')?.value || '4';

        if (!tableName) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError('Bitte gib einen Tischnamen ein.'); return; }

        // Find room_id — get from API since we don't always have it
        // Try fetching the room we just created by name
        const roomResp = await fetch('/admin/settings', { headers: { Accept: 'text/html' } });
        // Actually just use 0 as placeholder; the backend assigns room to location automatically.
        // Better: re-fetch rooms to get ID.
        const roomsResp = await fetch('/admin/floorplan/state', { headers: { Accept: 'application/json' } });
        let roomId = createdRoomId;
        if (roomsResp.ok) {
            const stateData = await roomsResp.json();
            const found = stateData?.rooms?.find(r => r.name === roomName);
            if (found) roomId = found.id;
        }

        const { ok: tok, json: tj } = await post(ROUTE_TABLES, {
            room_id: String(roomId),
            name: tableName,
            min_capacity: tableMin,
            max_capacity: tableMax,
            online_bookable: '1',
            joinable: '1',
        });
        btn.disabled = false; btn.textContent = 'Speichern & Weiter →';
        if (!tok) { showError(errMsg(tj)); return; }

        markDone(2);
        goTo(3);
    }

    // ── Step 2: Salon – Mitarbeiter + Leistung ────────────────────────────────
    async function saveStep2Salon(btn) {
        const staffName  = document.getElementById('obStaffName')?.value?.trim();
        const staffColor = document.getElementById('obStaffColor')?.value || '#0d9488';

        if (!staffName) { showError('Bitte gib einen Namen für den Mitarbeiter ein.'); return; }

        btn.disabled = true; btn.textContent = '…';

        // 1) Create staff
        if (!createdStaffId) {
            const { ok, json } = await post(ROUTE_STAFF, { name: staffName, color: staffColor, is_active: '1' });
            if (!ok) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError(errMsg(json)); return; }
            createdStaffId = json.id ?? 1;

            const status = document.getElementById('obStaffStatus');
            if (status) { status.textContent = 'Mitarbeiter angelegt ✓'; status.classList.remove('hidden'); }

            const serviceBox = document.getElementById('obServiceBox');
            if (serviceBox) { serviceBox.classList.remove('opacity-40', 'pointer-events-none'); }
        }

        // 2) Create service
        const serviceName     = document.getElementById('obServiceName')?.value?.trim();
        const serviceDuration = document.getElementById('obServiceDuration')?.value || '30';
        const servicePrice    = document.getElementById('obServicePrice')?.value || '0';

        if (!serviceName) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError('Bitte gib einen Leistungsnamen ein.'); return; }

        const priceMajor = parseFloat(servicePrice) || 0;
        const { ok: sok, json: sj } = await post(ROUTE_SERVICE, {
            name: serviceName,
            duration_minutes: serviceDuration,
            price_minor: String(Math.round(priceMajor * 100)),
            is_active: '1',
        });
        btn.disabled = false; btn.textContent = 'Speichern & Weiter →';
        if (!sok) { showError(errMsg(sj)); return; }

        markDone(2);
        goTo(3);
    }

    // ── Step 3: Buchungsregeln (optional) ────────────────────────────────────
    async function saveStep3(btn) {
        // Collect required fields — fill defaults for fields not on the wizard
        const body = {
            _method: 'PUT',
            slot_interval_minutes: '30',
            default_duration_minutes: '90',
            buffer_minutes: '0',
            min_lead_minutes: document.getElementById('obMinLead')?.value || '60',
            max_advance_days: document.getElementById('obMaxAdvance')?.value || '60',
            min_party_online: document.getElementById('obMinParty')?.value || '1',
            max_party_online: document.getElementById('obMaxParty')?.value || '10',
            auto_confirm: document.getElementById('obAutoConfirm')?.checked ? '1' : '',
            request_only: '',
            capacity_mode: 'table',
            max_covers_per_slot: '',
            waitlist_enabled: '',
            walkins_enabled: '',
            cancellation_deadline_minutes: '0',
            reminder_enabled: '',
            reminder_hours_before: '24',
            sms_reminder_enabled: '',
            gap_optimization_enabled: '',
            public_floorplan_enabled: '',
            refund_mode: 'off',
            refund_percent: '0',
            refund_processing: 'immediate',
            require_email_confirmation: document.getElementById('obEmailConfirm')?.checked ? '1' : '',
            confetti_on_booking: '',
            guest_address: 'Sie',
        };
        btn.disabled = true; btn.textContent = '…';
        const { ok, json } = await put(ROUTE_RULES, body);
        btn.disabled = false; btn.textContent = 'Speichern & Weiter →';
        if (!ok) { showError(errMsg(json)); return; }
        markDone(3);
        goTo(4);
    }

    // ── Step 4: Logo & Farbe (optional) ──────────────────────────────────────
    document.getElementById('obBrandColor')?.addEventListener('input', function () {
        const preview = document.getElementById('obColorPreview');
        if (preview) preview.style.background = this.value;
    });

    document.getElementById('obLogo')?.addEventListener('change', function () {
        const file = this.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.getElementById('obLogoPreview');
            if (wrap) wrap.innerHTML = `<img src="${e.target.result}" class="h-full w-full object-contain">`;
        };
        reader.readAsDataURL(file);
    });

    async function saveStep4(btn) {
        const color  = document.getElementById('obBrandColor')?.value;
        const file   = document.getElementById('obLogo')?.files?.[0];

        btn.disabled = true; btn.textContent = '…';

        // Save brand color
        if (color) {
            const { ok, json } = await put(ROUTE_BRANDING, { _method: 'PUT', brand_primary_color: color });
            if (!ok) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError(errMsg(json)); return; }
        }

        // Upload logo if provided
        if (file) {
            const fd = new FormData();
            fd.append('logo', file);
            fd.append('_token', CSRF);
            const { ok, json } = await post(ROUTE_LOGO, fd, true);
            if (!ok) { btn.disabled = false; btn.textContent = 'Speichern & Weiter →'; showError(errMsg(json)); return; }
        }

        btn.disabled = false; btn.textContent = 'Speichern & Weiter →';
        markDone(4);
        goTo(5);
    }

    // ── Button wiring ────────────────────────────────────────────────────────
    document.querySelectorAll('.ob-next').forEach(btn => {
        btn.addEventListener('click', async () => {
            clearError();
            const step = parseInt(btn.dataset.step, 10);
            if (step === 0) { await saveStep0(btn); return; }
            if (step === 1) { await saveStep1(btn); return; }
            if (step === 2) {
                if (IS_SALON) await saveStep2Salon(btn);
                else          await saveStep2Restaurant(btn);
                return;
            }
            if (step === 3) { await saveStep3(btn); return; }
            if (step === 4) { await saveStep4(btn); return; }
        });
    });

    document.querySelectorAll('.ob-back').forEach(btn => {
        btn.addEventListener('click', () => { clearError(); goTo(parseInt(btn.dataset.step, 10) - 1); });
    });

    document.querySelectorAll('.ob-skip').forEach(btn => {
        btn.addEventListener('click', () => { clearError(); goTo(parseInt(btn.dataset.step, 10) + 1); });
    });

    $sidebar.forEach((btn, i) => {
        btn.dataset.goto = i;
        btn.addEventListener('click', () => goTo(i));
    });

    // ── Resume from sessionStorage (after type-reload) ───────────────────────
    const savedStep = sessionStorage.getItem('ob_step');
    if (savedStep !== null) {
        sessionStorage.removeItem('ob_step');
        goTo(parseInt(savedStep, 10));
    } else {
        goTo(0);
    }
})();
</script>
</body>
</html>

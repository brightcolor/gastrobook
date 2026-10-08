<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Swayy') – Swayy</title>
    @php
        $swayyAdminData = [
            'ui' => [
                'toastMs' => config('swayy.ui.toast_ms'),
                'reloadDelayMs' => config('swayy.ui.reload_delay_ms'),
            ],
            'problemTexts' => \App\Support\AdminJsonErrors::forClient(),
        ];
    @endphp
    <script>
        window.SwayyAdmin = @json($swayyAdminData);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body data-admin class="min-h-screen bg-stone-100 text-stone-900 antialiased">
<a href="#main-content" class="skip-link">Zum Inhalt springen</a>
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
    $user = auth()->user();
    $routeName = request()->route()?->getName();
    $nav = \App\Support\Navigation\Navigation::forRequest(request());
    $settingsMode = $nav->isSettingsRoute($routeName);
    $mainGroups = $tenant ? $nav->main($user, $tenant, $location, $routeName) : [];
    $settingsGroups = $tenant && $settingsMode ? $nav->settings($user, $tenant, $location, $routeName) : [];
    $mobileBar = $tenant ? $nav->mobileBar($user, $tenant, $location, $routeName) : [];
    $settingsUrl = $tenant ? $nav->firstSettingsUrl($user, $tenant, $location) : null;
@endphp

<div class="flex min-h-screen">
    {{-- Seitenleiste: ab 1280 px voll, 768–1279 px einklappbar, darunter ausgeblendet --}}
    <aside id="swayyNav" data-nav-rail data-nav-mode="{{ $settingsMode ? 'settings' : 'main' }}"
           class="nav-rail sticky top-0 hidden h-dvh shrink-0 flex-col bg-stone-900 text-stone-100 md:flex">
        <div class="flex items-center justify-between gap-2 px-4 py-4">
            <span class="nav-full px-1 text-lg font-bold tracking-tight">Swayy<x-beta /></span>
            <button type="button" data-nav-collapse aria-controls="swayyNav" aria-expanded="true" aria-label="Navigation einklappen"
                    class="nav-collapse flex h-9 w-9 items-center justify-center rounded-md text-stone-300 hover:bg-stone-800 hover:text-white">
                <x-nav-icon name="collapse" />
            </button>
        </div>
        @if($tenant)
            @if($location)
                {{-- Nur in der eingeklappten Leiste: Standort anzeigen und Leiste ausklappen --}}
                <button type="button" data-nav-expand title="Standort: {{ $location->name }}"
                        class="nav-rail-only mx-4 mb-3 h-9 w-9 items-center justify-center rounded-md text-stone-300 hover:bg-stone-800 hover:text-white">
                    <x-nav-icon name="location" /><span class="sr-only">Standort: {{ $location->name }} – Navigation ausklappen</span>
                </button>
            @endif
            <div class="nav-full px-5 pb-3 text-xs text-stone-400">{{ $tenant->name }}</div>
            <div class="nav-full px-4 pb-4">
                @include('admin.partials.nav.location-switch', ['switchId' => 'locationSwitchDesktop'])
            </div>
        @endif
        <nav aria-label="{{ $settingsMode ? 'Einstellungen' : 'Hauptnavigation' }}" class="flex-1 overflow-y-auto px-3 pb-4 text-sm">
            @if($settingsMode)
                @include('admin.partials.nav.settings', ['groups' => $settingsGroups, 'searchId' => 'settingsSearchDesktop'])
            @else
                @include('admin.partials.nav.main', ['groups' => $mainGroups, 'settingsUrl' => $settingsUrl, 'settingsActive' => false])
            @endif
        </nav>
        @include('admin.partials.nav.user')
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Kopfleiste auf dem Smartphone --}}
        <header data-sheet-inert class="sticky top-0 z-30 flex min-h-12 items-center gap-2 bg-stone-900 px-3 text-stone-100 md:hidden">
            @if($settingsMode && $settingsUrl)
                {{-- Auf der Einstellungsübersicht selbst führt der Weg zurück zum Betrieb --}}
                @if($routeName === 'admin.settings.menu')
                    <a href="{{ route('admin.dashboard') }}" class="flex min-h-10 items-center gap-1.5 rounded-md px-2 text-sm hover:bg-stone-800">
                        <x-nav-icon name="back" /> Übersicht
                    </a>
                @else
                    <a href="{{ route('admin.settings.menu') }}" class="flex min-h-10 items-center gap-1.5 rounded-md px-2 text-sm hover:bg-stone-800">
                        <x-nav-icon name="back" /> Einstellungen
                    </a>
                @endif
            @else
                <span class="px-2 font-bold">Swayy<x-beta /></span>
            @endif
            @if($location)
                <span class="ml-auto truncate px-2 text-xs text-stone-400">{{ $location->name }}</span>
            @endif
        </header>

        <main id="main-content" class="flex-1 p-4 pb-24 md:p-6">
            @include('admin.partials.license_banner')
            @if(session('success'))
                <div data-flash role="status" class="mb-4 flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <span class="mt-px font-bold">✓</span><span>{{ session('success') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div data-flash="sticky" role="alert" class="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <div>
                        <p class="mb-1 font-semibold">Bitte prüfe diese Angaben:</p>
                        <ul class="list-disc space-y-0.5 pl-5">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif
            @yield('content')

            <footer class="mt-10 text-center text-[0.6875rem] text-stone-600">
                Swayy v{{ config('version.number') }} &middot; <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[0.625rem] font-semibold text-amber-800">Beta</span>
            </footer>
        </main>
    </div>
</div>

@if($tenant)
    {{-- Untere Leiste auf dem Smartphone: die häufigsten Ziele und „Mehr“ --}}
    <nav aria-label="Schnellzugriff" data-sheet-inert
         class="fixed inset-x-0 bottom-0 z-40 grid border-t border-stone-800 bg-stone-900 text-stone-300 md:hidden"
         style="grid-template-columns: repeat({{ count($mobileBar) + 1 }}, minmax(0, 1fr))">
        @foreach($mobileBar as $item)
            <a href="{{ $item->url }}" data-nav-key="{{ $item->key }}" @if($item->active) aria-current="page" @endif
               class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[0.6875rem] {{ $item->active ? 'font-semibold text-white shadow-[inset_0_2px_0_0_#fff]' : 'hover:text-white' }}">
                <x-nav-icon :name="$item->icon" class="h-5 w-5" /><span class="truncate">{{ $item->label }}</span>
            </a>
        @endforeach
        <button type="button" data-more-open aria-controls="swayyMore" aria-expanded="false"
                class="flex min-h-14 flex-col items-center justify-center gap-1 text-[0.6875rem] hover:text-white">
            <x-nav-icon name="more" class="h-5 w-5" />Mehr
        </button>
    </nav>

    {{-- „Mehr“: volle Navigation auf dem Smartphone --}}
    <div id="swayyMore" hidden class="fixed inset-0 z-50 md:hidden">
        <div class="absolute inset-0 bg-black/50" data-more-close></div>
        <div role="dialog" aria-modal="true" aria-label="Navigation"
             class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-stone-900 p-4 text-stone-100 shadow-2xl">
            <div class="mb-3 flex items-center justify-between">
                <span class="font-bold">{{ $tenant->name }}</span>
                <button type="button" data-more-close aria-label="Schließen"
                        class="flex h-10 w-10 items-center justify-center rounded-md text-2xl leading-none hover:bg-stone-800">×</button>
            </div>
            <div class="pb-3">
                @include('admin.partials.nav.location-switch', ['switchId' => 'locationSwitchMobile'])
            </div>
            <nav aria-label="Alle Bereiche" class="text-sm">
                @include('admin.partials.nav.main', [
                    'groups' => $mainGroups,
                    'settingsUrl' => $settingsUrl ? route('admin.settings.menu') : null,
                    'settingsActive' => $settingsMode,
                ])
            </nav>
            @include('admin.partials.nav.user')
        </div>
    </div>
@endif

@stack('drawers')

{{-- Hinweise („Gespeichert“, Fehler); resources/js/admin/toast.js --}}
<div id="swayyToast" data-overlay-layer role="status" aria-live="polite" data-state="ok"
     class="pointer-events-none fixed bottom-20 left-1/2 z-[60] flex max-w-[90vw] -translate-x-1/2 items-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white opacity-0 shadow-xl transition-opacity data-[state=error]:bg-red-700 data-[state=ok]:bg-stone-900 md:bottom-6"></div>

{{-- Global confirm dialog for forms/links with data-confirm (touch-friendly) --}}
<div id="confirmModal" data-overlay-layer role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
    <div class="w-[380px] max-w-[96vw] rounded-2xl bg-white p-6 text-center shadow-2xl">
        <h3 id="confirmModalTitle" class="mb-1 text-lg font-bold">Bestätigen</h3>
        <p id="confirmModalMsg" class="mb-5 text-sm text-stone-500"></p>
        <div class="flex gap-3">
            <button type="button" id="confirmModalCancel"
                    class="h-13 flex-1 rounded-xl border border-stone-200 bg-stone-50 py-3.5 text-base font-bold text-stone-700">Abbrechen</button>
            <button type="button" id="confirmModalOk"
                    class="h-13 flex-1 rounded-xl bg-stone-900 py-3.5 text-base font-bold text-white">OK</button>
        </div>
    </div>
</div>
<script>
// Any element with data-confirm intercepts its form submit (or click) and asks
// first via a touch-friendly modal. Optional: data-confirm-ok, data-confirm-style="danger".
(function () {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmModalTitle');
    const msgEl = document.getElementById('confirmModalMsg');
    const okBtn = document.getElementById('confirmModalOk');
    const cancelBtn = document.getElementById('confirmModalCancel');
    let pending = null;
    let lastFocused = null;

    function openModal() {
        lastFocused = document.activeElement;
        modal.classList.remove('hidden'); modal.classList.add('flex');
        okBtn.focus();
    }
    function closeModal() {
        modal.classList.add('hidden'); modal.classList.remove('flex'); pending = null;
        if (lastFocused && lastFocused.focus) lastFocused.focus();
    }

    okBtn.addEventListener('click', () => { const fn = pending; closeModal(); if (fn) fn(); });
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    // preventDefault: Der Dialog hat Escape verbraucht, eine darunter offene Seitenleiste (drawer.js) bleibt offen.
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) { e.preventDefault(); closeModal(); }
    });

    document.addEventListener('submit', e => {
        // Formulare mit data-ajax übernimmt forms.js (Rückfrage dort: x-inline-confirm).
        if (e.defaultPrevented || e.target.hasAttribute('data-ajax')) return;
        const el = e.target.querySelector('[data-confirm]') || (e.submitter && e.submitter.matches('[data-confirm]') ? e.submitter : null);
        const trigger = e.target.matches('[data-confirm]') ? e.target : el;
        if (!trigger) return;
        e.preventDefault();
        titleEl.textContent = trigger.dataset.confirmTitle || 'Bestätigen';
        msgEl.textContent = trigger.dataset.confirm;
        okBtn.textContent = trigger.dataset.confirmOk || 'OK';
        okBtn.className = 'h-13 flex-1 rounded-xl py-3.5 text-base font-bold text-white '
            + (trigger.dataset.confirmStyle === 'danger' ? 'bg-red-600' : 'bg-emerald-600');
        pending = () => e.target.submit();
        openModal();
    });
})();
</script>

@stack('scripts')
</body>
</html>

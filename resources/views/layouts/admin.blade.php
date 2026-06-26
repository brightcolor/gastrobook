<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Swayy') – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
    $user = auth()->user();

    // Helper: wrap a path (or raw body) in a standard nav SVG
    $si = function (string $d, bool $raw = false): string {
        $body = $raw ? $d : '<path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/>';
        return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">'.$body.'</svg>';
    };

    $isSalon = $tenant?->isSalon();
    $items = [
        ['route' => 'admin.dashboard',          'label' => 'Dashboard',                        'perm' => null,               'icon' => $si('M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z')],
        ['route' => 'admin.board',              'label' => 'Live-Board',                       'perm' => 'reservations.view', 'icon' => $si('M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.808-3.808-9.98 0-13.789m13.788 0c3.808 3.808 3.808 9.981 0 13.79M12 12h.008v.007H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z')],
        ['route' => 'admin.reservations.index', 'label' => $isSalon ? 'Termine' : 'Reservierungen', 'perm' => 'reservations.view', 'icon' => $si('M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z')],
        ...($isSalon ? [
            ['route' => 'admin.services.index', 'label' => 'Leistungen',  'perm' => 'tables.manage', 'icon' => $si('M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z')],
            ['route' => 'admin.staff.index',    'label' => 'Mitarbeiter', 'perm' => 'tables.manage', 'icon' => $si('M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z')],
        ] : [
            ['route' => 'admin.floorplan.index', 'label' => 'Tischplan', 'perm' => 'reservations.view', 'icon' => $si('M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z')],
        ]),
        ['route' => 'admin.walkins.index',      'label' => 'Walk-ins',        'perm' => 'walkins.create',    'icon' => $si('M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l-3-3m0 0l3-3m-3 3H15')],
        ['route' => 'admin.waitlist.index',     'label' => 'Warteliste',      'perm' => 'waitlist.manage',   'icon' => $si('M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z')],
        ['route' => 'admin.guests.index',       'label' => 'Kunden',          'perm' => 'guests.view',       'icon' => $si('M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z')],
        ['route' => 'admin.events.index',       'label' => 'Events',          'perm' => 'events.manage',     'icon' => $si('M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z')],
        ['route' => 'admin.refunds.index',      'label' => 'Rückerstattungen','perm' => 'payments.manage',   'icon' => $si('M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z')],
        ['route' => 'admin.reports.index',      'label' => 'Berichte',        'perm' => 'reports.view',      'icon' => $si('M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z')],
        ['route' => 'admin.settings.index',     'label' => 'Einstellungen',   'perm' => 'tables.manage',     'icon' => $si('<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>', true)],
        ['route' => 'admin.locations.index',    'label' => 'Standorte',       'perm' => 'locations.manage',  'icon' => $si('<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>', true)],
        ['route' => 'admin.users.index',        'label' => 'Benutzer',        'perm' => 'users.invite',      'icon' => $si('M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z')],
        ['route' => 'admin.billing.show',       'label' => 'Abrechnung',      'perm' => 'billing.manage',    'icon' => $si('M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z')],
        ['route' => 'admin.api-tokens.index',   'label' => 'API',             'perm' => 'api_tokens.manage', 'icon' => $si('M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5')],
        ['route' => 'admin.audit.index',        'label' => 'Auditlog',        'perm' => 'audit.view',        'icon' => $si('M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z')],
    ];

    $visibleItems = collect($items)->filter(fn ($item) => $item['perm'] === null
        || ($tenant && $user->canInTenant($item['perm'], $tenant, $location)));
@endphp

<div class="flex min-h-screen">
    {{-- Sidebar (Desktop) --}}
    <aside class="hidden w-60 shrink-0 flex-col bg-stone-900 text-stone-100 md:flex">
        <div class="px-5 py-5 text-lg font-bold tracking-tight">Swayy</div>
        @if($tenant)
            <div class="px-5 pb-3 text-xs text-stone-400">{{ $tenant->name }}</div>
            <form method="POST" action="{{ route('admin.switch-location') }}" class="px-4 pb-4">
                @csrf
                <select name="location_id" onchange="this.form.submit()"
                        class="w-full rounded-md border-0 bg-stone-800 px-2 py-1.5 text-sm text-stone-100">
                    @foreach($tenant->locations as $loc)
                        <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        <nav class="flex-1 space-y-0.5 px-3 text-sm">
            @foreach($visibleItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-2 rounded-md px-3 py-2 {{ request()->routeIs($item['route']) ? 'bg-stone-700 font-semibold' : 'hover:bg-stone-800' }}">
                    {!! $item['icon'] !!} {{ $item['label'] }}
                </a>
            @endforeach
            @if($user->isSaasAdmin())
                <div class="my-2 border-t border-stone-700"></div>
                <a href="{{ route('saas.tenants.index') }}" class="flex items-center gap-2 rounded-md px-3 py-2 hover:bg-stone-800">{!! $si('M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21') !!} SaaS-Admin</a>
            @endif
        </nav>
        <div class="border-t border-stone-700 p-4 text-sm">
            <div class="mb-2 truncate text-stone-300">{{ $user->name }}</div>
            @if(session('impersonating_tenant_id'))
                <form method="POST" action="{{ route('saas.stop-impersonation') }}" class="mb-2">
                    @csrf
                    <button class="w-full rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold">Supportzugriff beenden</button>
                </form>
            @endif
            <a href="{{ route('admin.account.show') }}"
               class="mb-1 block w-full rounded-md px-3 py-1.5 text-center text-xs text-stone-400 hover:bg-stone-700 hover:text-stone-100">
                Mein Konto
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full rounded-md bg-stone-700 px-3 py-1.5 text-xs hover:bg-stone-600">Abmelden</button>
            </form>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between bg-stone-900 px-4 py-3 text-stone-100 md:hidden">
            <button type="button" onclick="swayyToggleMenu(true)" aria-label="Menü öffnen" class="-ml-1 flex h-9 w-9 items-center justify-center rounded-md text-2xl leading-none hover:bg-stone-800">☰</button>
            <span class="font-bold">Swayy</span>
            <span class="h-9 w-9"></span>
        </header>

        <main class="flex-1 p-4 md:p-6">
            @include('admin.partials.license_banner')
            @if(session('success'))
                <div class="mb-4 flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <span class="mt-px font-bold">✓</span><span>{{ session('success') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <p class="mb-1 font-semibold">Bitte prüfen Sie Ihre Eingaben:</p>
                    <ul class="list-disc space-y-0.5 pl-5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            @yield('content')

            <footer class="mt-10 text-center text-[11px] text-stone-400">
                Swayy v{{ config('version.number') }} &middot; <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600">Beta</span>
            </footer>
        </main>
    </div>
</div>

{{-- Mobile menu drawer --}}
<div id="swayyMobileMenu" class="fixed inset-0 z-50 hidden md:hidden">
    <div class="absolute inset-0 bg-black/50" onclick="swayyToggleMenu(false)"></div>
    <aside class="absolute left-0 top-0 flex h-full w-72 max-w-[82%] flex-col bg-stone-900 text-stone-100 shadow-2xl">
        <div class="flex items-center justify-between px-5 py-4">
            <span class="text-lg font-bold tracking-tight">Swayy</span>
            <button type="button" onclick="swayyToggleMenu(false)" aria-label="Menü schließen" class="flex h-9 w-9 items-center justify-center rounded-md text-2xl leading-none hover:bg-stone-800">×</button>
        </div>
        @if($tenant)
            <div class="px-5 pb-2 text-xs text-stone-400">{{ $tenant->name }}</div>
            <form method="POST" action="{{ route('admin.switch-location') }}" class="px-4 pb-3">
                @csrf
                <select name="location_id" onchange="this.form.submit()"
                        class="w-full rounded-md border-0 bg-stone-800 px-2 py-1.5 text-sm text-stone-100">
                    @foreach($tenant->locations as $loc)
                        <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 text-sm">
            @foreach($visibleItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-2 rounded-md px-3 py-2.5 {{ request()->routeIs($item['route']) ? 'bg-stone-700 font-semibold' : 'hover:bg-stone-800' }}">
                    {!! $item['icon'] !!} {{ $item['label'] }}
                </a>
            @endforeach
            @if($user->isSaasAdmin())
                <div class="my-2 border-t border-stone-700"></div>
                <a href="{{ route('saas.tenants.index') }}" class="flex items-center gap-2 rounded-md px-3 py-2.5 hover:bg-stone-800">{!! $si('M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21') !!} SaaS-Admin</a>
            @endif
        </nav>
        <div class="border-t border-stone-700 p-4 text-sm">
            <div class="mb-2 truncate text-stone-300">{{ $user->name }}</div>
            @if(session('impersonating_tenant_id'))
                <form method="POST" action="{{ route('saas.stop-impersonation') }}" class="mb-2">
                    @csrf
                    <button class="w-full rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold">Supportzugriff beenden</button>
                </form>
            @endif
            <a href="{{ route('admin.account.show') }}"
               class="mb-1 block w-full rounded-md px-3 py-2 text-center text-xs text-stone-400 hover:bg-stone-700 hover:text-stone-100">
                Mein Konto
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full rounded-md bg-stone-700 px-3 py-2 text-xs hover:bg-stone-600">Abmelden</button>
            </form>
        </div>
    </aside>
</div>

<script>
    function swayyToggleMenu(open) {
        const m = document.getElementById('swayyMobileMenu');
        if (!m) return;
        m.classList.toggle('hidden', open === false);
        document.body.style.overflow = m.classList.contains('hidden') ? '' : 'hidden';
    }
</script>
@stack('scripts')
</body>
</html>

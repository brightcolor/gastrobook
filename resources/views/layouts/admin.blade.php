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

    $isSalon = $tenant?->isSalon();
    $items = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => '📊', 'perm' => null],
        ['route' => 'admin.board', 'label' => 'Live-Board', 'icon' => '🟢', 'perm' => 'reservations.view'],
        ['route' => 'admin.reservations.index', 'label' => $isSalon ? 'Termine' : 'Reservierungen', 'icon' => '📖', 'perm' => 'reservations.view'],
        ...($isSalon ? [
            ['route' => 'admin.services.index', 'label' => 'Leistungen', 'icon' => '✂️', 'perm' => 'tables.manage'],
            ['route' => 'admin.staff.index', 'label' => 'Mitarbeiter', 'icon' => '👤', 'perm' => 'tables.manage'],
        ] : [
            ['route' => 'admin.floorplan.index', 'label' => 'Tischplan', 'icon' => '🪑', 'perm' => 'reservations.view'],
        ]),
        ['route' => 'admin.walkins.index', 'label' => 'Walk-ins', 'icon' => '🚶', 'perm' => 'walkins.create'],
        ['route' => 'admin.waitlist.index', 'label' => 'Warteliste', 'icon' => '⏳', 'perm' => 'waitlist.manage'],
        ['route' => 'admin.guests.index', 'label' => 'Kunden', 'icon' => '👥', 'perm' => 'guests.view'],
        ['route' => 'admin.events.index', 'label' => 'Events', 'icon' => '🎉', 'perm' => 'events.manage'],
        ['route' => 'admin.refunds.index', 'label' => 'Rückerstattungen', 'icon' => '💶', 'perm' => 'payments.manage'],
        ['route' => 'admin.reports.index', 'label' => 'Berichte', 'icon' => '📈', 'perm' => 'reports.view'],
        ['route' => 'admin.settings.index', 'label' => 'Einstellungen', 'icon' => '⚙️', 'perm' => 'tables.manage'],
        ['route' => 'admin.users.index', 'label' => 'Benutzer', 'icon' => '🔑', 'perm' => 'users.invite'],
        ['route' => 'admin.api-tokens.index', 'label' => 'API', 'icon' => '🔌', 'perm' => 'api_tokens.manage'],
        ['route' => 'admin.audit.index', 'label' => 'Auditlog', 'icon' => '🛡️', 'perm' => 'audit.view'],
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
                    <span>{{ $item['icon'] }}</span> {{ $item['label'] }}
                </a>
            @endforeach
            @if($user->isSaasAdmin())
                <div class="my-2 border-t border-stone-700"></div>
                <a href="{{ route('saas.tenants.index') }}" class="flex items-center gap-2 rounded-md px-3 py-2 hover:bg-stone-800">🏢 SaaS-Admin</a>
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
            @if(session('success'))
                <div class="mb-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
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
                Swayy v{{ config('version.number') }}
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
                    <span>{{ $item['icon'] }}</span> {{ $item['label'] }}
                </a>
            @endforeach
            @if($user->isSaasAdmin())
                <div class="my-2 border-t border-stone-700"></div>
                <a href="{{ route('saas.tenants.index') }}" class="flex items-center gap-2 rounded-md px-3 py-2.5 hover:bg-stone-800">🏢 SaaS-Admin</a>
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

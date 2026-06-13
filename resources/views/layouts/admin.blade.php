<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'GastroBook') – GastroBook</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
    $user = auth()->user();
@endphp
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside class="hidden w-60 shrink-0 flex-col bg-stone-900 text-stone-100 md:flex">
        <div class="px-5 py-5 text-lg font-bold tracking-tight">🍽️ GastroBook</div>
        @if($tenant)
            <div class="px-5 pb-3 text-xs text-stone-400">{{ $tenant->name }}</div>
            @if($tenant->locations()->count() > 1 || true)
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
        @endif
        <nav class="flex-1 space-y-0.5 px-3 text-sm">
            @php
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
            @endphp
            @foreach($items as $item)
                @if($item['perm'] === null || ($tenant && $user->canInTenant($item['perm'], $tenant, $location)))
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-2 rounded-md px-3 py-2 {{ request()->routeIs($item['route']) ? 'bg-stone-700 font-semibold' : 'hover:bg-stone-800' }}">
                        <span>{{ $item['icon'] }}</span> {{ $item['label'] }}
                    </a>
                @endif
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
            <span class="font-bold">🍽️ GastroBook</span>
            <nav class="flex gap-3 text-xl">
                <a href="{{ route('admin.dashboard') }}">📊</a>
                <a href="{{ route('admin.reservations.index') }}">📖</a>
                <a href="{{ route('admin.floorplan.index') }}">🪑</a>
                <a href="{{ route('admin.waitlist.index') }}">⏳</a>
            </nav>
        </header>

        <main class="flex-1 p-4 md:p-6">
            @if(session('success'))
                <div class="mb-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">
                    <ul class="list-disc pl-4">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            @yield('content')

            <footer class="mt-10 text-center text-[11px] text-stone-400">
                GastroBook v{{ config('version.number') }}
            </footer>
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>

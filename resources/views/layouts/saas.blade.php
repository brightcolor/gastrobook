<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SaaS-Admin') – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100">
@php
    $nav = [
        ['route' => 'saas.dashboard', 'label' => 'Dashboard'],
        ['route' => 'saas.tenants.index', 'label' => 'Mandanten'],
        ['route' => 'saas.users.index', 'label' => 'Benutzer'],
    ];
@endphp
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside class="hidden w-56 shrink-0 flex-col bg-stone-900 text-stone-100 md:flex">
        <div class="px-5 py-5 text-lg font-bold tracking-tight">Swayy <span class="ml-1 rounded bg-stone-700 px-1.5 py-0.5 text-[10px] font-semibold text-stone-300">SaaS</span></div>
        <nav class="flex-1 space-y-0.5 px-3 text-sm">
            @foreach($nav as $item)
                <a href="{{ route($item['route']) }}"
                   class="block rounded-md px-3 py-2 {{ request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*') ? 'bg-stone-700 font-semibold' : 'hover:bg-stone-800' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
        <div class="border-t border-stone-700 p-4 text-sm">
            <div class="mb-2 truncate text-stone-300">{{ auth()->user()->name }}</div>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="w-full rounded-md bg-stone-700 px-3 py-1.5 text-xs hover:bg-stone-600">Abmelden</button>
            </form>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Mobile top bar --}}
        <header class="flex items-center justify-between bg-stone-900 px-4 py-3 text-stone-100 md:hidden">
            <span class="font-bold">Swayy SaaS</span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-xs">Abmelden</button></form>
        </header>
        {{-- Mobile nav --}}
        <nav class="flex gap-1 overflow-x-auto bg-stone-800 px-3 py-2 text-sm text-stone-100 md:hidden">
            @foreach($nav as $item)
                <a href="{{ route($item['route']) }}" class="whitespace-nowrap rounded-md px-3 py-1.5 {{ request()->routeIs($item['route']) ? 'bg-stone-600 font-semibold' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <main class="flex-1 p-5 sm:p-8">
            @if(session('success'))
                <div class="mb-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
            @endif

            @yield('content')

            <footer class="mt-10 text-center text-[11px] text-stone-400">Swayy v{{ config('version.number') }}</footer>
        </main>
    </div>
</div>
</body>
</html>

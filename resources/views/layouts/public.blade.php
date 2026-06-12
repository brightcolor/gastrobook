<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reservierung')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php($primary = $tenant->brand_primary_color ?? '#0f766e')
    <style>
        :root { --brand: {{ $primary }}; }
        .btn-brand { background-color: var(--brand); }
        .text-brand { color: var(--brand); }
        .ring-brand { --tw-ring-color: var(--brand); }
        .border-brand { border-color: var(--brand); }
    </style>
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
    <main class="mx-auto max-w-lg px-4 py-6">
        @yield('content')
        @hasSection('hide_branding')
        @else
            @if(($tenant ?? null) && $tenant->plan?->key === 'trial')
                <p class="mt-8 text-center text-xs text-stone-400">Bereitgestellt mit GastroBook</p>
            @endif
        @endif
    </main>
</body>
</html>

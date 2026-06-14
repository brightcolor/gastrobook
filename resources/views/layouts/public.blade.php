<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reservierung')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php($primary = $tenant->brand_primary_color ?? '#0f766e')
    <style>
        :root { --brand: {{ $primary }}; --brand-strong: color-mix(in oklab, {{ $primary }} 82%, black); }
        .text-brand { color: var(--brand); }
        .ring-brand { --tw-ring-color: var(--brand); }
        .border-brand { border-color: var(--brand); }
        body {
            background:
                radial-gradient(1100px 460px at 50% -12%, color-mix(in oklab, var(--brand) 16%, transparent), transparent 72%),
                linear-gradient(180deg, #fbfbfa 0%, #f4f4f2 100%);
            background-attachment: fixed;
        }
    </style>
</head>
<body class="min-h-screen text-stone-900 antialiased">
    <main class="mx-auto max-w-xl px-4 py-10 sm:py-14">
        @yield('content')
        @hasSection('hide_branding')
        @else
            @if(($tenant ?? null) && $tenant->plan?->key === 'trial')
                <p class="mt-10 text-center text-xs font-medium text-stone-400">
                    Bereitgestellt mit <span class="font-semibold text-stone-500">Swayy</span>
                </p>
            @endif
        @endif
    </main>
    <script>
        // Auto-resize when embedded as iframe via /embed/{tenant}/{location}.js
        if (window.parent !== window) {
            const send = () => parent.postMessage({swayyHeight: document.body.scrollHeight + 40}, '*');
            new ResizeObserver(send).observe(document.body);
            window.addEventListener('load', send);
        }
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') – Swayy</title>
    @vite(['resources/css/app.css'])
    @include('partials.favicons')
</head>
{{-- Eigenständige Seite außerhalb des Verwaltungsrahmens: Ein Fehler kann schon vor der Anmeldung oder bei einem Konto
     ohne Betrieb entstehen. Von der Ausnahme erreicht die Seite höchstens den Grund aus abort() bei 403 und 503
     (App\Support\ErrorPage::reason()); Pfade, SQL und Zugangsdaten bleiben draußen. --}}
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
<div class="mx-auto flex min-h-screen w-full max-w-lg flex-col justify-center px-4 py-10">
    <p class="mb-6 flex items-center justify-center gap-2.5 font-display text-2xl font-medium">
        <img src="/logo-mark.svg" alt="" class="h-9 w-9 rounded-xl"> Swayy
    </p>
    <main id="main-content" class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight">@yield('title')</h1>
        <p class="mt-3 text-stone-700">@yield('cause')</p>
        <p class="mt-2 text-stone-700">@yield('step')</p>
        <div class="mt-6 flex flex-wrap gap-3">
            @hasSection('actions')
                @yield('actions')
            @else
                @include('errors.partials.home-links')
            @endif
        </div>
        <p class="mt-6 text-xs text-stone-600">Fehlercode @yield('code')</p>
    </main>
</div>
</body>
</html>

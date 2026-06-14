<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Swayy – Buchungssystem für Restaurants & Salons')</title>
    <meta name="description" content="@yield('description', 'Reservierungen & Termine, Live-Board, Tischplan bzw. Mitarbeiter-Dienstplan, Zahlungen und No-Show-Schutz – die Buchungsplattform für Restaurants und Friseure/Dienstleister. DSGVO-konform, EU-Hosting. 30 Tage kostenlos.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-stone-900 antialiased">
    <header class="sticky top-0 z-40 border-b border-stone-200 bg-white/90 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('home') }}" class="text-xl font-extrabold tracking-tight">Swayy</a>
            <div class="hidden items-center gap-6 text-sm font-medium md:flex">
                <a href="{{ route('home') }}#branchen" class="hover:text-teal-700">Branchen</a>
                <a href="{{ route('home') }}#hauptfunktionen" class="hover:text-teal-700">Funktionen</a>
                <a href="{{ route('home') }}#preise" class="hover:text-teal-700">Preise</a>
                <a href="{{ route('home') }}#faq" class="hover:text-teal-700">FAQ</a>
                <a href="{{ route('contact') }}" class="hover:text-teal-700">Kontakt</a>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="text-sm font-semibold text-stone-600 hover:text-stone-900">Anmelden</a>
                <a href="{{ route('register') }}" class="rounded-xl bg-teal-700 px-4 py-2 text-sm font-bold text-white hover:bg-teal-800">Kostenlos testen</a>
            </div>
        </nav>
    </header>

    <main>
        @if(session('success'))
            <div class="mx-auto max-w-6xl px-4 pt-4">
                <div class="rounded-xl bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('success') }}</div>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t border-stone-200 bg-stone-50">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <div class="flex flex-col items-start justify-between gap-6 md:flex-row">
                <div>
                    <p class="text-lg font-extrabold">Swayy</p>
                    <p class="mt-1 max-w-xs text-sm text-stone-500">Die Buchungsplattform für Restaurants, Cafés, Bars sowie Friseure und Dienstleister. Gehostet in der EU.</p>
                </div>
                <div class="grid grid-cols-2 gap-x-12 gap-y-2 text-sm">
                    <a href="{{ route('home') }}#hauptfunktionen" class="text-stone-600 hover:text-stone-900">Funktionen</a>
                    <a href="{{ route('legal.imprint') }}" class="text-stone-600 hover:text-stone-900">Impressum</a>
                    <a href="{{ route('home') }}#preise" class="text-stone-600 hover:text-stone-900">Preise</a>
                    <a href="{{ route('legal.privacy') }}" class="text-stone-600 hover:text-stone-900">Datenschutz</a>
                    <a href="{{ route('contact') }}" class="text-stone-600 hover:text-stone-900">Kontakt</a>
                    <a href="{{ route('legal.terms') }}" class="text-stone-600 hover:text-stone-900">AGB</a>
                </div>
            </div>
            <p class="mt-8 text-xs text-stone-400">© {{ date('Y') }} Swayy · Alle Preise zzgl. USt.</p>
        </div>
    </footer>
</body>
</html>

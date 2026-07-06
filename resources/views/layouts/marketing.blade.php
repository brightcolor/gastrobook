<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Swayy – Buchungssystem für Restaurants & Salons')</title>
    <meta name="description" content="@yield('description', 'Reservierungen & Termine, Live-Board, Tischplan, Zahlungen und No-Show-Schutz – die Buchungsplattform für Restaurants und Friseure. DSGVO-konform, EU-Hosting. 30 Tage kostenlos.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
    @stack('styles')
</head>
<body class="min-h-screen bg-white text-stone-900 antialiased">

    <div id="scrollProgress" class="fixed left-0 top-0 z-[70] h-[2.5px] w-0" style="background:linear-gradient(90deg,#14b8a6,#0f766e); transition:width .1s linear" aria-hidden="true"></div>

    <header id="mkt-header" class="sticky top-0 z-50 transition-all duration-300">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3.5">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-2xl tracking-tight" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500">
                <img src="/logo-mark.svg" alt="" class="h-8 w-8 shadow-sm" style="border-radius:0.75rem">
                <span>Swayy</span>
            </a>
            <div class="hidden items-center gap-1 text-sm font-medium md:flex">
                <a href="{{ route('home') }}#branchen"    class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900">Branchen</a>
                <a href="{{ route('home') }}#funktionen"  class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900">Funktionen</a>
                <a href="{{ route('home') }}#preise"          class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900">Preise</a>
                <a href="{{ route('home') }}#faq"             class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900">FAQ</a>
                <a href="{{ route('contact') }}"              class="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900">Kontakt</a>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-stone-600 hover:text-stone-900 sm:block">Anmelden</a>
                <a href="{{ route('register') }}" class="rounded-xl bg-teal-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-teal-700">
                    Kostenlos testen
                </a>
            </div>
        </nav>
    </header>

    <main>
        @if(session('success'))
            <div class="mx-auto max-w-6xl px-4 pt-4">
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('success') }}</div>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t border-stone-200 bg-stone-50">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <div class="flex flex-col items-start justify-between gap-8 md:flex-row">
                <div>
                    <div class="flex items-center gap-2.5">
                        <img src="/logo-mark.svg" alt="" class="h-8 w-8" style="border-radius:0.75rem">
                        <p class="text-xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500">Swayy</p>
                    </div>
                    <p class="mt-2 max-w-xs text-sm text-stone-500 leading-relaxed">Die Buchungsplattform für Restaurants, Cafés, Bars sowie Friseure und Dienstleister. Gehostet in der EU.</p>
                </div>
                <div class="grid grid-cols-2 gap-x-16 gap-y-2.5 text-sm">
                    <a href="{{ route('home') }}#funktionen" class="text-stone-500 hover:text-stone-900">Funktionen</a>
                    <a href="{{ route('legal.imprint') }}"        class="text-stone-500 hover:text-stone-900">Impressum</a>
                    <a href="{{ route('home') }}#preise"          class="text-stone-500 hover:text-stone-900">Preise</a>
                    <a href="{{ route('legal.privacy') }}"        class="text-stone-500 hover:text-stone-900">Datenschutz</a>
                    <a href="{{ route('contact') }}"              class="text-stone-500 hover:text-stone-900">Kontakt</a>
                    <a href="{{ route('legal.terms') }}"          class="text-stone-500 hover:text-stone-900">AGB</a>
                </div>
            </div>
            <div class="mt-10 flex flex-col items-start justify-between gap-2 border-t border-stone-200 pt-6 text-xs text-stone-400 sm:flex-row">
                <p>© {{ date('Y') }} Swayy · Alle Preise zzgl. MwSt.</p>
                <p>🇪🇺 EU-Hosting · DSGVO-konform · ohne Provision</p>
            </div>
        </div>
    </footer>

    <script>
    // Nav becomes opaque on scroll + thin brand scroll-progress bar
    (function () {
        const hdr = document.getElementById('mkt-header');
        const bar = document.getElementById('scrollProgress');
        const update = () => {
            const scrolled = window.scrollY > 20;
            if (hdr) {
                hdr.style.background    = scrolled ? 'rgba(255,255,255,0.9)' : 'transparent';
                hdr.style.backdropFilter = scrolled ? 'saturate(160%) blur(14px)' : 'none';
                hdr.style.borderBottom  = scrolled ? '1px solid rgba(0,0,0,.07)' : '1px solid transparent';
                hdr.style.boxShadow     = scrolled ? '0 1px 16px rgba(0,0,0,.06)' : 'none';
            }
            if (bar) {
                const max = document.documentElement.scrollHeight - window.innerHeight;
                bar.style.width = (max > 0 ? (window.scrollY / max) * 100 : 0) + '%';
            }
        };
        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update, { passive: true });
        update();
    })();
    </script>
</body>
</html>

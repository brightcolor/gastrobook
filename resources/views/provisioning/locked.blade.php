<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $tenant->name }} – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="text-xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500">„{{ $tenant->name }}“ ist {{ $label }}</h1>
        <p class="mt-3 text-sm text-stone-700">
            @if($tenant->status === 'cancelled')
                Der Vertrag für diesen Betrieb ist beendet, die Buchungsseiten nehmen keine Reservierungen mehr an. Die Daten bleiben bis zur Löschung erhalten. Möchtest du weitermachen, schreib uns an {{ config('swayy.support.email') }}, solange die Daten noch da sind.
            @else
                Die Verwaltung dieses Betriebs ist gesperrt, die Buchungsseiten nehmen keine Reservierungen an. Den Grund und den Weg zurück findest du im Kundenbereich unter „Verträge“.
            @endif
        </p>
        <div class="mt-6 flex flex-col gap-3">
            @if($panelUrl !== '')
                <a href="{{ $panelUrl }}" class="rounded-lg bg-stone-900 px-4 py-2.5 text-center text-sm font-medium text-white">Zum Kundenbereich</a>
            @endif
            <a href="mailto:{{ config('swayy.support.email') }}" class="text-center text-sm text-stone-700 underline">Fragen an {{ config('swayy.support.email') }}</a>
            <form method="POST" action="{{ route('logout') }}" class="text-center">
                @csrf
                <button type="submit" class="text-sm text-stone-500 underline">Abmelden</button>
            </form>
        </div>
    </div>
</body>
</html>

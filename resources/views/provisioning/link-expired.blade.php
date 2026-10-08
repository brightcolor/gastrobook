<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Link abgelaufen – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="text-xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500">Dieser Anmeldelink gilt nicht mehr</h1>
        <p class="mt-3 text-sm text-stone-700">
            Ein Link aus dem Kundenbereich gilt einmal und nur kurz. Öffne im Kundenbereich unter „Verträge“ noch einmal „Zum Dienst“, dann bekommst du einen neuen.
        </p>
        <div class="mt-6 flex flex-col gap-3">
            @if($panelUrl !== '')
                <a href="{{ $panelUrl }}" class="rounded-lg bg-stone-900 px-4 py-2.5 text-center text-sm font-medium text-white">Zum Kundenbereich</a>
            @endif
            <a href="{{ route('login') }}" class="text-center text-sm text-stone-700 underline">Mit eigenem Passwort anmelden</a>
        </div>
    </div>
</body>
</html>

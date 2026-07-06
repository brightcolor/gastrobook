<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abmelden – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="flex items-center justify-center gap-2.5 text-2xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500"><img src="/logo-mark.svg" alt="" class="h-9 w-9" style="border-radius:0.85rem"> Swayy</h1>
        <p class="mt-1 text-center text-sm text-stone-500">
            Angemeldet als <span class="font-semibold text-stone-700">{{ $user->email }}</span>
        </p>

        <form method="POST" action="{{ route('logout') }}" class="mt-6 space-y-3">
            @csrf
            <button class="w-full rounded-xl bg-stone-900 py-3 font-bold text-white hover:bg-stone-700">Abmelden</button>
        </form>

        <a href="{{ route('admin.dashboard') }}"
           class="mt-3 block w-full rounded-xl border-2 border-stone-200 py-3 text-center font-semibold text-stone-700 hover:bg-stone-50">
            Zum Dashboard
        </a>
    </div>
</body>
</html>

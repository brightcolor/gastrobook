<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abmelden – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="text-center text-2xl font-bold">Swayy</h1>
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

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmelden – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="flex items-center justify-center gap-2.5 text-2xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500"><img src="/logo-mark.png" alt="" class="h-9 w-9" style="border-radius:0.85rem"> Swayy</h1>
        <p class="mt-1 text-center text-sm text-stone-500">Reservierungsmanagement</p>

        @if(session('status'))
            <div class="mt-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-1 block text-sm font-semibold">E-Mail</label>
                <input type="email" name="email" id="email" required autofocus value="{{ old('email') }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <div class="flex items-center justify-between">
                    <label for="password" class="mb-1 block text-sm font-semibold">Passwort</label>
                    <a href="{{ route('password.request') }}" class="text-xs text-teal-700 hover:underline">Passwort vergessen?</a>
                </div>
                <input type="password" name="password" id="password" required
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1"> Angemeldet bleiben
            </label>
            <button class="w-full rounded-xl bg-stone-900 py-3 font-bold text-white hover:bg-stone-700">Anmelden</button>
        </form>

        <p class="mt-6 text-center text-sm text-stone-500">
            Noch kein Konto? <a href="{{ route('register') }}" class="font-semibold text-teal-700">Kostenlos testen</a>
        </p>
    </div>
</body>
</html>

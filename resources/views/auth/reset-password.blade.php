<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neues Passwort – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="flex items-center justify-center gap-2.5 text-2xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500"><img src="/logo-mark.svg" alt="" class="h-9 w-9" style="border-radius:0.85rem"> Swayy</h1>
        <p class="mt-1 text-center text-sm text-stone-500">Neues Passwort vergeben</p>

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="mb-1 block text-sm font-semibold">E-Mail</label>
                <input type="email" name="email" id="email" required value="{{ old('email', $email) }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-semibold">Neues Passwort</label>
                <input type="password" name="password" id="password" required autocomplete="new-password"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                <p class="mt-1 text-xs text-stone-400">Mind. 8 Zeichen, Groß-/Kleinbuchstaben und eine Zahl</p>
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-semibold">Passwort bestätigen</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <button class="w-full rounded-xl bg-stone-900 py-3 font-bold text-white hover:bg-stone-700">
                Passwort speichern
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-stone-500">
            <a href="{{ route('login') }}" class="font-semibold text-teal-700">← Zurück zur Anmeldung</a>
        </p>
    </div>
</body>
</html>

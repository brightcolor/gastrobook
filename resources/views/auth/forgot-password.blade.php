<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort vergessen – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="text-center text-2xl font-bold">Swayy</h1>
        <p class="mt-1 text-center text-sm text-stone-500">Passwort zurücksetzen</p>

        @if(session('status'))
            <div class="mt-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        @unless(session('status'))
            <p class="mt-4 text-sm text-stone-600">
                Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen einen Link, mit dem Sie ein neues Passwort vergeben können.
            </p>

            <form method="POST" action="{{ route('password.email') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold">E-Mail</label>
                    <input type="email" name="email" id="email" required autofocus value="{{ old('email') }}"
                           class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                <button class="w-full rounded-xl bg-stone-900 py-3 font-bold text-white hover:bg-stone-700">
                    Reset-Link senden
                </button>
            </form>
        @endunless

        <p class="mt-6 text-center text-sm text-stone-500">
            <a href="{{ route('login') }}" class="font-semibold text-teal-700">← Zurück zur Anmeldung</a>
        </p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kostenlos registrieren – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.favicons')
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4 py-10">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="flex items-center justify-center gap-2.5 text-2xl" style="font-family:var(--font-display,'Fraunces Variable',serif); font-weight:500"><img src="/logo-mark.png" alt="" class="h-9 w-9" style="border-radius:0.85rem"> Swayy</h1>
        <p class="mt-1 text-center text-sm text-stone-500">30 Tage kostenlos testen – keine Zahlungsdaten nötig</p>

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf
            <input type="text" name="website" value="" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div>
                <label for="restaurant_name" class="mb-1 block text-sm font-semibold">Name des Restaurants</label>
                <input type="text" name="restaurant_name" id="restaurant_name" required maxlength="120" value="{{ old('restaurant_name') }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3" placeholder="z. B. Gasthaus Sonne">
            </div>
            <div>
                <label for="name" class="mb-1 block text-sm font-semibold">Ihr Name</label>
                <input type="text" name="name" id="name" required maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="email" class="mb-1 block text-sm font-semibold">E-Mail</label>
                <input type="email" name="email" id="email" required value="{{ old('email') }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-semibold">Passwort <span class="font-normal text-stone-400">(mind. 10 Zeichen)</span></label>
                <input type="password" name="password" id="password" required minlength="10"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-semibold">Passwort wiederholen</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required minlength="10"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="privacy_accepted" value="1" required class="mt-1">
                <span>Ich akzeptiere die <a href="{{ route('legal.terms') }}" target="_blank" class="font-semibold text-teal-700 underline">AGB</a> und habe die <a href="{{ route('legal.privacy') }}" target="_blank" class="font-semibold text-teal-700 underline">Datenschutzerklärung</a> gelesen.</span>
            </label>
            <button class="w-full rounded-xl bg-teal-700 py-3 font-bold text-white hover:bg-teal-800">Kostenlos starten</button>
        </form>

        <p class="mt-6 text-center text-sm text-stone-500">
            Bereits ein Konto? <a href="{{ route('login') }}" class="font-semibold text-teal-700">Anmelden</a>
        </p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Einladung annehmen – Swayy</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-900 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-xl">
        <h1 class="text-center text-2xl font-bold">Einladung annehmen</h1>
        <p class="mt-2 text-center text-sm text-stone-500">
            Sie wurden als <strong>{{ $invitation->role }}</strong> eingeladen ({{ $invitation->email }}).
        </p>

        @if($errors->any())
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('invitation.accept.post', ['token' => $invitation->token]) }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="mb-1 block text-sm font-semibold">Ihr Name</label>
                <input type="text" name="name" id="name" required class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-semibold">Passwort (min. 10 Zeichen)</label>
                <input type="password" name="password" id="password" required minlength="10" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-semibold">Passwort wiederholen</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            </div>
            <button class="w-full rounded-xl bg-stone-900 py-3 font-bold text-white hover:bg-stone-700">Konto erstellen</button>
        </form>
    </div>
</body>
</html>

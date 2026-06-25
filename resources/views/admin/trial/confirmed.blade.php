<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail bestätigt – Swayy</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">

<div class="flex min-h-screen items-center justify-center px-4 py-20">
    <div class="w-full max-w-md text-center">

        <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-teal-50">
            <svg class="h-10 w-10 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-stone-900">E-Mail bestätigt!</h1>
        <p class="mx-auto mt-4 max-w-sm leading-relaxed text-stone-500">
            Vielen Dank, <strong class="text-stone-700">{{ $billingRequest->contact_name }}</strong>.
            Ihre Anfrage für den Tarif <strong class="text-stone-700">{{ $billingRequest->plan_key }}</strong>
            wurde übermittelt. Wir melden uns in Kürze bei Ihnen.
        </p>

        <p class="mt-6 text-sm text-stone-400">
            Fragen? <a href="mailto:info@swayy.de" class="text-teal-600 hover:underline">info@swayy.de</a>
        </p>

        @auth
            <form action="{{ route('logout') }}" method="POST" class="mt-6">
                @csrf
                <button type="submit" class="text-sm text-stone-400 hover:text-stone-600 underline">Abmelden</button>
            </form>
        @endauth

    </div>
</div>

</body>
</html>

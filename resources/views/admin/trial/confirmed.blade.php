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

        <h1 class="text-2xl font-bold text-stone-900">Konto wieder aktiv!</h1>
        <p class="mx-auto mt-4 max-w-sm leading-relaxed text-stone-500">
            Danke, <strong class="text-stone-700">{{ $billingRequest->contact_name }}</strong>.
            Ihr Swayy-Konto ist freigeschaltet — Sie können sofort weitermachen.
            Die Zahlungsdetails klären wir separat mit Ihnen.
        </p>

        <a href="{{ route('admin.dashboard') }}"
           class="mt-8 inline-block rounded-xl bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700">
            Zum Dashboard →
        </a>

        <p class="mt-6 text-sm text-stone-400">
            Fragen? <a href="mailto:info@swayy.de" class="text-teal-600 hover:underline">info@swayy.de</a>
        </p>

    </div>
</div>

</body>
</html>

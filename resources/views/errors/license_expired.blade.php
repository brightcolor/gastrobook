<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lizenz abgelaufen – Swayy</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background: #fafaf9; font-family: system-ui, sans-serif; }
    </style>
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-50 px-4">
<div class="mx-auto max-w-md text-center">
    <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 text-3xl">🔒</div>
    <h1 class="mb-2 text-2xl font-extrabold tracking-tight text-stone-900">Lizenz abgelaufen</h1>
    <p class="mb-6 text-stone-500">
        @if($status->revoked)
            Diese Swayy-Lizenz wurde widerrufen. Bitte kontaktieren Sie uns.
        @else
            Die Swayy-Lizenz für diese Installation ist abgelaufen.<br>
            Bitte erneuern Sie Ihre Lizenz, um wieder Zugriff zu erhalten.
        @endif
    </p>

    @if($status->licenseId)
        <p class="mb-6 rounded-xl bg-stone-100 px-4 py-3 font-mono text-xs text-stone-500">
            Lizenz-ID: {{ $status->licenseId }}
        </p>
    @endif

    <a href="https://swayy.de/kontakt"
       class="inline-flex items-center gap-2 rounded-xl bg-stone-900 px-6 py-3 text-sm font-semibold text-white hover:bg-stone-700">
        Lizenz erneuern →
    </a>

    <p class="mt-8 text-xs text-stone-400">
        Die Buchungsseite für Ihre Gäste ist weiterhin erreichbar.
    </p>
</div>
</body>
</html>

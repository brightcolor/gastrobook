@php
    $ls = $licenseStatus ?? null;
    if (!$ls || !$ls->selfHosted) return;
    $days = $ls->daysLeft();
@endphp

@if($ls->inGracePeriod)
    {{-- Expired but within grace period --}}
    <div class="mb-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <span class="mt-px text-base">🔒</span>
        <div>
            <span class="font-semibold">Lizenz abgelaufen</span> —
            Bitte erneuern Sie Ihre Swayy-Lizenz. Der Admin-Bereich wird in
            {{ abs($days ?? 0) < 14 ? (14 - abs($days ?? 0)) : 0 }} Tag(en) gesperrt.
            <a href="https://swayy.de/kontakt" class="ml-1 underline">Jetzt erneuern →</a>
        </div>
    </div>
@elseif($ls->warningShouldShow())
    {{-- Expiring soon --}}
    <div class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <span class="mt-px text-base">⚠️</span>
        <div>
            <span class="font-semibold">Lizenz läuft ab</span> —
            Ihre Swayy-Lizenz ist noch {{ $days }} Tag(e) gültig (bis {{ $ls->expiresAt?->format('d.m.Y') }}).
            <a href="https://swayy.de/kontakt" class="ml-1 underline">Jetzt verlängern →</a>
        </div>
    </div>
@endif

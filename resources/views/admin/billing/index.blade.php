@extends('layouts.admin')
@section('title', 'Abo & Rechnungen')

@section('content')
<x-page-header title="Abo & Rechnungen" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Dein Tarif und wie die monatliche Gebühr bezahlt wird." />

<x-settings.section id="tarif" title="Tarif">
    <x-slot:description>
        <p>Der Tarif legt fest, wie viele Standorte, Zugänge, Tische und Events möglich sind. Ist eine Grenze erreicht, sagt Swayy das beim Anlegen.</p>
    </x-slot:description>
    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt class="font-semibold text-stone-600">Tarif</dt>
        <dd>{{ $plan?->name ?? 'Noch kein Tarif zugeordnet' }}</dd>
        @if($plan && (int) $plan->price_monthly_minor > 0)
            <dt class="font-semibold text-stone-600">Preis</dt>
            <dd>{{ number_format($plan->price_monthly_minor / 100, 2, ',', '.') }} {{ strtoupper($plan->currency ?? 'EUR') }} im Monat</dd>
        @endif
    </dl>
</x-settings.section>

<x-settings.section id="lastschrift" title="SEPA-Lastschrift">
    <x-slot:description>
        <p>Mit einem Mandat zieht Swayy die monatliche Gebühr von deinem Konto ein. Kündigen kannst du es jederzeit hier.</p>
    </x-slot:description>
    @if(! $configured)
        <p class="text-sm text-stone-600">Lastschrift ist gerade nicht verfügbar. Bitte später erneut versuchen oder den Support fragen.</p>
    @elseif($profile && $profile->hasActiveDirectDebit())
        <p class="flex flex-wrap items-center gap-2 text-sm">
            <x-status-icon state="ok" label="Aktiv" />
            <span class="font-semibold">Aktiv</span>
            <span class="text-stone-600">· Abbuchung monatlich per SEPA-Lastschrift</span>
        </p>
        <x-inline-confirm id="lastschrift-kuendigen" :action="route('admin.billing.directdebit.cancel')" :ajax="false"
                          :open="$errors->has('billing')"
                          label="Lastschrift kündigen" confirm="Lastschrift kündigen"
                          question="Lastschrift kündigen? Danach zieht Swayy keine Gebühren mehr von deinem Konto ein." />
    @elseif($plan && (int) $plan->price_monthly_minor > 0)
        <p class="text-sm text-stone-600">Richte ein SEPA-Lastschriftmandat ein, sicher über GoCardless. Zur Bestätigung leitet Swayy dich dorthin weiter.</p>
        <a href="{{ route('admin.billing.directdebit.setup') }}" class="btn btn-primary">Per Lastschrift einrichten</a>
    @else
        <p class="text-sm text-stone-600">Dein Tarif kostet nichts; eine Lastschrift brauchst du nicht.</p>
    @endif
</x-settings.section>
@endsection

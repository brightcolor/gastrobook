@extends('layouts.admin')
@section('title', 'Abrechnung')
@section('content')

<h1 class="mb-6 text-2xl font-bold">Abrechnung</h1>

<div class="max-w-lg space-y-5">

    {{-- Tarif --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Tarif</h2>
        <div class="space-y-1 text-sm text-stone-700">
            <p><span class="font-semibold text-stone-500">Tarif:</span> {{ $plan?->name ?? '—' }}</p>
            @if($plan && (int) $plan->price_monthly_minor > 0)
                <p><span class="font-semibold text-stone-500">Preis:</span>
                    {{ number_format($plan->price_monthly_minor / 100, 2, ',', '.') }} {{ strtoupper($plan->currency ?? 'EUR') }} / Monat</p>
            @endif
        </div>
    </div>

    {{-- SEPA-Lastschrift --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">SEPA-Lastschrift</h2>

        @if($errors->has('billing'))
            <p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first('billing') }}</p>
        @endif
        @if(session('success'))
            <p class="mb-3 rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-700">{{ session('success') }}</p>
        @endif

        @if(! $configured)
            <p class="text-sm text-stone-500">Lastschrift ist derzeit nicht verfügbar.</p>
        @elseif($profile && $profile->hasActiveDirectDebit())
            <div class="mb-4 flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-teal-500"></span>
                <span class="font-semibold text-teal-700">Aktiv</span>
                <span class="text-stone-500">– Abbuchung monatlich per SEPA-Lastschrift</span>
            </div>
            <form method="POST" action="{{ route('admin.billing.directdebit.cancel') }}"
                  onsubmit="return confirm('Lastschrift wirklich kündigen? Es erfolgen keine weiteren Abbuchungen.')">
                @csrf
                <button class="rounded-xl border-2 border-red-400 px-5 py-2 text-sm font-bold text-red-600 hover:bg-red-50">
                    Lastschrift kündigen
                </button>
            </form>
        @elseif($plan && (int) $plan->price_monthly_minor > 0)
            <p class="mb-4 text-sm text-stone-600">
                Richte ein SEPA-Lastschriftmandat ein – sicher über GoCardless, ohne Kreditkarte.
                Du wirst zur Autorisierung weitergeleitet und kannst jederzeit hier wieder kündigen.
            </p>
            <a href="{{ route('admin.billing.directdebit.setup') }}"
               class="inline-block rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-stone-800">
                Per Lastschrift einrichten →
            </a>
        @else
            <p class="text-sm text-stone-500">Für den aktuellen Tarif ist keine Lastschrift nötig.</p>
        @endif
    </div>
</div>
@endsection

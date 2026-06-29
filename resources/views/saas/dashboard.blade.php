@extends('layouts.saas')
@section('title', 'Dashboard')
@section('content')

<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold">Dashboard</h1>
    <a href="{{ route('saas.tenants.index') }}" class="rounded-xl bg-stone-900 px-4 py-2 text-sm font-bold text-white">+ Mandant anlegen</a>
</div>

{{-- KPI cards --}}
<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    @php
        $cards = [
            ['label' => 'Mandanten', 'value' => $tenantsTotal, 'sub' => ($byStatus['active'] ?? 0).' aktiv'],
            ['label' => 'In Testphase', 'value' => $trialing, 'sub' => 'Trial läuft'],
            ['label' => 'Benutzer', 'value' => $usersTotal, 'sub' => $saasAdmins.' Plattform-Admins'],
            ['label' => 'Reservierungen', 'value' => $reservationsThisMonth, 'sub' => 'diesen Monat'],
        ];
    @endphp
    @foreach($cards as $c)
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">{{ $c['label'] }}</p>
            <p class="mt-1 text-3xl font-bold">{{ $c['value'] }}</p>
            <p class="mt-0.5 text-xs text-stone-500">{{ $c['sub'] }}</p>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    {{-- Status breakdown --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Mandanten nach Status</h2>
        <div class="space-y-2 text-sm">
            @php
                $statusLabels = ['active' => 'Aktiv', 'trial_expired' => 'Trial abgelaufen', 'pending_billing' => 'Billing ausstehend', 'suspended' => 'Gesperrt', 'cancelled' => 'Gekündigt'];
                $statusColors = ['active' => 'bg-emerald-500', 'trial_expired' => 'bg-amber-500', 'pending_billing' => 'bg-sky-500', 'suspended' => 'bg-red-500', 'cancelled' => 'bg-stone-400'];
            @endphp
            @forelse($byStatus as $status => $count)
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-2">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $statusColors[$status] ?? 'bg-stone-400' }}"></span>
                        {{ $statusLabels[$status] ?? $status }}
                    </span>
                    <span class="font-semibold">{{ $count }}</span>
                </div>
            @empty
                <p class="text-stone-400">Noch keine Mandanten.</p>
            @endforelse
        </div>
    </div>

    {{-- Plans --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Tarife</h2>
        <div class="space-y-2 text-sm">
            @foreach($plans as $plan)
                <div class="flex items-center justify-between">
                    <span>{{ $plan->name }} <span class="text-xs text-stone-400">{{ number_format($plan->price_monthly_minor / 100, 0) }} {{ $plan->currency }}/Mt.</span></span>
                    <span class="font-semibold">{{ $plan->tenants_count }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recent tenants --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Zuletzt angelegt</h2>
        <div class="space-y-2 text-sm">
            @forelse($recentTenants as $t)
                <a href="{{ route('saas.tenants.index') }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-stone-50">
                    <span class="truncate">{{ $t->name }}</span>
                    <span class="ml-2 shrink-0 text-xs text-stone-400">{{ $t->created_at->format('d.m.Y') }}</span>
                </a>
            @empty
                <p class="text-stone-400">—</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

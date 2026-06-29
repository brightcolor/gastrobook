@extends('layouts.saas')
@section('title', 'Mandanten')
@section('content')

<div class="mb-6 flex items-center justify-between gap-4">
    <h1 class="text-2xl font-bold">Mandanten</h1>
    <button type="button" onclick="document.getElementById('createTenant').classList.toggle('hidden')"
            class="rounded-xl bg-stone-900 px-4 py-2 text-sm font-bold text-white">+ Mandant anlegen</button>
</div>

{{-- Create form (collapsible) --}}
<div id="createTenant" class="mb-6 hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-3 font-bold">Neuer Mandant</h2>
    <form method="POST" action="{{ route('saas.tenants.store') }}" class="grid gap-3 text-sm sm:grid-cols-2">
        @csrf
        <label class="block sm:col-span-2">Name des Betriebs *
            <input type="text" name="name" required value="{{ old('name') }}" placeholder="z. B. Restaurantgruppe Müller" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Tarif *
            <select name="plan_id" required class="mt-1 w-full rounded-lg border-stone-200">
                @foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }}</option>@endforeach
            </select>
        </label>
        <label class="block">Erster Standort *
            <input type="text" name="location_name" required value="{{ old('location_name') }}" placeholder="z. B. Restaurant Sonne" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Inhaber: Name *
            <input type="text" name="owner_name" required value="{{ old('owner_name') }}" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Inhaber: E-Mail *
            <input type="email" name="owner_email" required value="{{ old('owner_email') }}" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <div class="sm:col-span-2">
            <button class="rounded-xl bg-stone-900 px-5 py-2.5 font-bold text-white">Anlegen</button>
            <span class="ml-2 text-xs text-stone-400">Ein Initialpasswort wird generiert und einmalig angezeigt.</span>
        </div>
    </form>
</div>

<form method="GET" class="mb-4">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Mandant suchen…"
           class="w-full max-w-sm rounded-xl border-stone-200 text-sm">
</form>

@php
    $statusLabels = ['active' => 'Aktiv', 'suspended' => 'Gesperrt', 'cancelled' => 'Gekündigt', 'trial_expired' => 'Trial abgelaufen', 'pending_billing' => 'Billing ausstehend'];
    $statusBadge = ['active' => 'bg-emerald-100 text-emerald-700', 'suspended' => 'bg-red-100 text-red-700', 'cancelled' => 'bg-stone-200 text-stone-600', 'trial_expired' => 'bg-amber-100 text-amber-700', 'pending_billing' => 'bg-sky-100 text-sky-700'];
@endphp

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($tenants as $tenant)
        <div class="flex flex-col rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-bold">{{ $tenant->name }}</p>
                    <p class="truncate text-xs text-stone-400">{{ $tenant->slug }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge[$tenant->status] ?? 'bg-stone-100 text-stone-600' }}">
                    {{ $statusLabels[$tenant->status] ?? $tenant->status }}
                </span>
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                <div class="rounded-lg bg-stone-50 py-2"><div class="font-bold">{{ $tenant->locations_count }}</div><div class="text-[11px] text-stone-400">Standorte</div></div>
                <div class="rounded-lg bg-stone-50 py-2"><div class="font-bold">{{ $tenant->memberships_count }}</div><div class="text-[11px] text-stone-400">Benutzer</div></div>
                <div class="rounded-lg bg-stone-50 py-2"><div class="font-bold">{{ $reservationCounts[$tenant->id] ?? 0 }}</div><div class="text-[11px] text-stone-400">Res./Mt.</div></div>
            </div>

            <div class="mt-4 space-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-xs text-stone-400">Tarif</span>
                    <form method="POST" action="{{ route('saas.tenants.plan', $tenant) }}" class="flex-1">
                        @csrf @method('PUT')
                        <select name="plan_id" onchange="this.form.submit()" class="w-full rounded-lg border-stone-200 text-xs">
                            @foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($tenant->plan_id === $plan->id)>{{ $plan->name }}</option>@endforeach
                        </select>
                    </form>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-xs text-stone-400">Status</span>
                    <form method="POST" action="{{ route('saas.tenants.status', $tenant) }}" class="flex-1">
                        @csrf @method('PUT')
                        <select name="status" onchange="this.form.submit()" class="w-full rounded-lg border-stone-200 text-xs">
                            @if(in_array($tenant->status, ['trial_expired','pending_billing'], true))
                                <option value="" selected disabled>{{ $statusLabels[$tenant->status] }}</option>
                            @endif
                            @foreach(['active' => 'Aktiv', 'suspended' => 'Gesperrt', 'cancelled' => 'Gekündigt'] as $val => $label)
                                <option value="{{ $val }}" @selected($tenant->status === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-xs text-stone-400">Trial</span>
                    <span class="flex-1 text-xs text-stone-500">{{ $tenant->trial_ends_at ? $tenant->trial_ends_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') : '—' }}</span>
                    <form method="POST" action="{{ route('saas.tenants.trial', $tenant) }}" class="flex items-center gap-1">
                        @csrf @method('PUT')
                        <input type="number" name="days" value="30" min="1" max="365" class="w-14 rounded-lg border-stone-200 text-xs">
                        <button class="rounded-lg bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-200" title="Trial verlängern + aktivieren">+ Tage</button>
                    </form>
                </div>
            </div>

            <form method="POST" action="{{ route('saas.tenants.impersonate', $tenant) }}" class="mt-4">
                @csrf
                <input type="hidden" name="reason" value="Support">
                <button class="w-full rounded-xl bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-200">Supportzugriff starten</button>
            </form>
        </div>
    @empty
        <p class="text-sm text-stone-500">Keine Mandanten gefunden.</p>
    @endforelse
</div>

<div class="mt-6">{{ $tenants->withQueryString()->links() }}</div>
@endsection

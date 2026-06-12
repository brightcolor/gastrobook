@extends('layouts.admin')
@section('title', 'API & Webhooks')
@section('content')
<h1 class="mb-5 text-2xl font-bold">API-Zugriff</h1>

@if(session('new_token'))
    <div class="mb-4 rounded-2xl bg-emerald-50 p-4 text-sm">
        <p class="font-bold text-emerald-900">Neuer Token erstellt – jetzt kopieren, er wird nur einmal angezeigt:</p>
        <code class="mt-2 block break-all rounded-lg bg-white p-3">{{ session('new_token') }}</code>
    </div>
@endif

@unless($apiEnabled)
    <div class="mb-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Die API ist im aktuellen Tarif nicht enthalten.</div>
@endunless

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Aktive Tokens</h2>
        <div class="mt-3 divide-y divide-stone-50 text-sm">
            @forelse($tokens as $token)
                <div class="flex items-center justify-between py-2.5">
                    <div>
                        <strong>{{ $token->name }}</strong>
                        <div class="text-xs text-stone-500">{{ collect($token->abilities)->reject(fn ($a) => str_starts_with($a, 'tenant:'))->implode(', ') }}</div>
                        <div class="text-xs text-stone-400">Zuletzt verwendet: {{ $token->last_used_at?->diffForHumans() ?? 'nie' }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.api-tokens.destroy', $token->id) }}" onsubmit="return confirm('Token widerrufen?')">
                        @csrf @method('DELETE')
                        <button class="text-red-500 hover:underline">Widerrufen</button>
                    </form>
                </div>
            @empty
                <p class="py-3 text-stone-500">Noch keine Tokens.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Token erstellen</h2>
        <form method="POST" action="{{ route('admin.api-tokens.store') }}" class="mt-3 space-y-3 text-sm">
            @csrf
            <input type="text" name="name" required placeholder="Bezeichnung (z. B. Website-Widget)" class="w-full rounded-lg border-stone-200">
            <div class="grid grid-cols-2 gap-1.5">
                @foreach($scopes as $scope)
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="scopes[]" value="{{ $scope }}"> <code class="text-xs">{{ $scope }}</code></label>
                @endforeach
            </div>
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white" @unless($apiEnabled) disabled style="opacity:.5" @endunless>Token erstellen</button>
        </form>
        <div class="mt-4 rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
            <p class="font-semibold">Beispiel:</p>
            <code class="mt-1 block">curl -H "Authorization: Bearer &lt;TOKEN&gt;" {{ url('/api/v1/reservations') }}</code>
        </div>
    </div>
</div>
@endsection

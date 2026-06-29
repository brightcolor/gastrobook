@extends('layouts.saas')
@section('title', 'Benutzer')
@section('content')

@php
    $roleLabels = ['super_admin' => 'Super-Admin', 'support_admin' => 'Support-Admin', 'billing_admin' => 'Billing-Admin', 'readonly_admin' => 'Nur-Lesen-Admin'];
@endphp

<div class="mb-6 flex items-center justify-between gap-4">
    <h1 class="text-2xl font-bold">Benutzer</h1>
    @if($canManage)
        <button type="button" onclick="document.getElementById('createUser').classList.toggle('hidden')"
                class="rounded-xl bg-stone-900 px-4 py-2 text-sm font-bold text-white">+ Benutzer anlegen</button>
    @endif
</div>

@if($canManage)
<div id="createUser" class="mb-6 hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-3 font-bold">Neuer Benutzer</h2>
    <form method="POST" action="{{ route('saas.users.store') }}" class="grid gap-3 text-sm sm:grid-cols-2">
        @csrf
        <label class="block">Name *
            <input type="text" name="name" required value="{{ old('name') }}" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">E-Mail *
            <input type="email" name="email" required value="{{ old('email') }}" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Passwort * <span class="text-xs text-stone-400">(min. 10 Zeichen)</span>
            <input type="text" name="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Plattform-Rolle
            <select name="saas_role" class="mt-1 w-full rounded-lg border-stone-200">
                <option value="">— keine (normaler Nutzer) —</option>
                @foreach($saasRoles as $role)<option value="{{ $role }}">{{ $roleLabels[$role] ?? $role }}</option>@endforeach
            </select>
        </label>
        <div class="sm:col-span-2">
            <button class="rounded-xl bg-stone-900 px-5 py-2.5 font-bold text-white">Anlegen</button>
            <span class="ml-2 text-xs text-stone-400">Ohne Plattform-Rolle ist es ein normaler Nutzer (muss noch einem Mandanten zugeordnet werden).</span>
        </div>
    </form>
</div>
@endif

<form method="GET" class="mb-4">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name oder E-Mail suchen…"
           class="w-full max-w-sm rounded-xl border-stone-200 text-sm">
</form>

<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
    <table class="w-full text-sm">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
            <tr>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">E-Mail</th>
                <th class="px-4 py-3">Plattform-Rolle</th>
                <th class="px-4 py-3">Mandanten</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50">
            @foreach($users as $u)
                <tr class="hover:bg-stone-50/70">
                    <td class="px-4 py-3 font-semibold">{{ $u->name }}</td>
                    <td class="px-4 py-3 text-stone-500">{{ $u->email }}</td>
                    <td class="px-4 py-3">
                        @if($canManage)
                            <form method="POST" action="{{ route('saas.users.role', $u) }}">
                                @csrf @method('PUT')
                                <select name="saas_role" onchange="this.form.submit()" class="rounded-lg border-stone-200 text-xs">
                                    <option value="" @selected($u->saas_role === null)>— normaler Nutzer —</option>
                                    @foreach($saasRoles as $role)<option value="{{ $role }}" @selected($u->saas_role === $role)>{{ $roleLabels[$role] ?? $role }}</option>@endforeach
                                </select>
                            </form>
                        @else
                            <span class="text-xs">{{ $u->saas_role ? ($roleLabels[$u->saas_role] ?? $u->saas_role) : '—' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-stone-500">{{ $u->tenant_memberships_count }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($canManage && $u->id !== auth()->id())
                            <form method="POST" action="{{ route('saas.users.destroy', $u) }}" onsubmit="return confirm('Benutzer „{{ $u->name }}“ endgültig löschen?')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-red-500 hover:text-red-700">Löschen</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-6">{{ $users->links() }}</div>
@endsection

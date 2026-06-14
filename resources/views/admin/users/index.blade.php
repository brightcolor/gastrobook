@extends('layouts.admin')
@section('title', 'Benutzer')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Benutzer & Rollen</h1>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
            <table class="w-full min-w-[42rem] text-sm">
                <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">E-Mail</th><th class="px-4 py-3">Rolle</th><th class="px-4 py-3">Standorte</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-stone-50 [&>tr:hover]:bg-stone-50/70">
                    @foreach($memberships as $m)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $m->user->name }}</td>
                            <td class="px-4 py-3 text-stone-500">{{ $m->user->email }}</td>
                            <td class="px-4 py-3">
                                @if(auth()->user()->canInTenant('users.roles.manage', $tenant))
                                    <form method="POST" action="{{ route('admin.users.role', $m) }}">
                                        @csrf @method('PUT')
                                        <select name="role" onchange="this.form.submit()" class="rounded-lg border-stone-200 text-sm">
                                            @foreach($roles as $role)<option value="{{ $role }}" @selected($m->role === $role)>{{ $role }}</option>@endforeach
                                        </select>
                                    </form>
                                @else
                                    {{ $m->role }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-500">{{ $m->all_locations ? 'Alle' : 'Eingeschränkt' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if(auth()->user()->canInTenant('users.roles.manage', $tenant) && $m->user_id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.remove', $m) }}" onsubmit="return confirm('Benutzer entfernen?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-500 hover:underline">Entfernen</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($invitations->isNotEmpty())
            <h2 class="mt-6 mb-2 font-bold">Offene Einladungen</h2>
            <div class="rounded-2xl bg-white p-4 text-sm shadow-sm">
                @foreach($invitations as $inv)
                    <div class="flex items-center justify-between border-b border-stone-50 py-2 last:border-0">
                        <span>{{ $inv->email }} ({{ $inv->role }})</span>
                        <code class="rounded bg-stone-100 px-2 py-0.5 text-xs">{{ route('invitation.accept', $inv->token) }}</code>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="font-bold">Benutzer einladen</h2>
        <form method="POST" action="{{ route('admin.users.invite') }}" class="mt-3 space-y-3 text-sm">
            @csrf
            <input type="email" name="email" required placeholder="E-Mail *" class="w-full rounded-lg border-stone-200">
            <select name="role" required class="w-full rounded-lg border-stone-200">
                @foreach($roles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2"><input type="checkbox" name="all_locations" value="1" checked id="allLoc"> Zugriff auf alle Standorte</label>
            <select name="location_ids[]" multiple class="w-full rounded-lg border-stone-200">
                @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
            </select>
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Einladen</button>
        </form>
        <p class="mt-3 text-xs text-stone-500">Bestehende Benutzer werden direkt hinzugefügt, neue erhalten einen Einladungslink.</p>
    </div>
</div>
@endsection

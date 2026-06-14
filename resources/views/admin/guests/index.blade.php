@extends('layouts.admin')
@section('title', 'Gäste')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Gästedatenbank</h1>
    @if(auth()->user()->canInTenant('guests.export', app(\App\Support\TenantContext::class)->tenant()))
        <a href="{{ route('admin.guests.export') }}" class="rounded-xl bg-stone-200 px-4 py-2.5 text-sm font-semibold hover:bg-stone-300">CSV-Export</a>
    @endif
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    <div class="grow">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, Telefon oder E-Mail…" class="w-full rounded-lg border-stone-200 text-sm">
    </div>
    <select name="tag" class="rounded-lg border-stone-200 text-sm">
        <option value="">Alle Tags</option>
        @foreach($tags as $tag)
            <option value="{{ $tag->id }}" @selected(request('tag') == $tag->id)>{{ $tag->name }}</option>
        @endforeach
    </select>
    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="vip" value="1" @checked(request('vip'))> Nur VIP</label>
    <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Suchen</button>
</form>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="border-b border-stone-100 text-left text-xs uppercase text-stone-500">
            <tr>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">Kontakt</th>
                <th class="px-4 py-3">Besuche</th>
                <th class="px-4 py-3">No-Shows</th>
                <th class="px-4 py-3">Letzter Besuch</th>
                <th class="px-4 py-3">Tags</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50">
            @forelse($guests as $guest)
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.guests.show', $guest) }}" class="font-semibold hover:underline">
                            {{ $guest->fullName() }} @if($guest->is_vip)⭐@endif
                        </a>
                        @if($guest->anonymized)<span class="text-xs text-stone-400">(anonymisiert)</span>@endif
                    </td>
                    <td class="px-4 py-3 text-stone-500">{{ $guest->email }}<br>{{ $guest->phone }}</td>
                    <td class="px-4 py-3">{{ $guest->visit_count }}</td>
                    <td class="px-4 py-3 {{ $guest->no_show_count > 0 ? 'font-semibold text-red-600' : '' }}">{{ $guest->no_show_count }}</td>
                    <td class="px-4 py-3">{{ $guest->last_visit_at?->format('d.m.Y') ?? '–' }}</td>
                    <td class="px-4 py-3">
                        @foreach($guest->tags as $tag)
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background:{{ $tag->color }}22;color:{{ $tag->color }}">{{ $tag->name }}</span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-stone-500">Keine Gäste gefunden.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $guests->links() }}</div>
@endsection

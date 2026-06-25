@extends('layouts.admin')

@section('title', 'Billing-Anfragen')

@section('content')
<div class="max-w-6xl">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold">Billing-Anfragen</h1>
            <p class="mt-0.5 text-sm text-stone-500">Bestätigte Kundenanfragen nach Trial-Ablauf</p>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-100 bg-stone-50 text-left text-xs font-semibold uppercase tracking-wider text-stone-400">
                <tr>
                    <th class="px-5 py-3">Betrieb</th>
                    <th class="px-5 py-3">Kontakt</th>
                    <th class="px-5 py-3">Tarif</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Bestätigt</th>
                    <th class="px-5 py-3">Aktion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse($requests as $req)
                    <tr class="transition hover:bg-stone-50">
                        <td class="px-5 py-4">
                            <div class="font-semibold text-stone-900">{{ $req->tenant->name }}</div>
                            <div class="text-xs text-stone-400">ID {{ $req->tenant_id }}</div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="font-medium">{{ $req->contact_name }}</div>
                            <a href="mailto:{{ $req->contact_email }}" class="text-xs text-teal-600 hover:underline">{{ $req->contact_email }}</a>
                            @if($req->phone)
                                <div class="text-xs text-stone-400">{{ $req->phone }}</div>
                            @endif
                            <div class="mt-1 text-xs text-stone-500">
                                {{ $req->address_line1 }}, {{ $req->postal_code }} {{ $req->city }}
                                @if($req->vat_id) · {{ $req->vat_id }} @endif
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <span class="rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-semibold text-teal-700">
                                {{ $req->plan_key }}
                            </span>
                            @if($req->notes)
                                <p class="mt-1 max-w-xs truncate text-xs text-stone-400" title="{{ $req->notes }}">{{ $req->notes }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            @if($req->tenant->status === 'active')
                                <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">✓ Aktiv</span>
                            @elseif($req->confirmed_at)
                                <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">⏳ Wartet auf Aktivierung</span>
                            @else
                                <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-500">E-Mail unbestätigt</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-xs text-stone-500">
                            @if($req->confirmed_at)
                                {{ $req->confirmed_at->format('d.m.Y H:i') }}
                            @else
                                –
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            @if($req->confirmed_at && $req->tenant->status !== 'active')
                                <form action="{{ route('admin.billing-requests.activate', $req->id) }}" method="POST">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700"
                                            onclick="return confirm('Konto {{ addslashes($req->tenant->name) }} aktivieren?')">
                                        Konto freischalten
                                    </button>
                                </form>
                            @elseif($req->tenant->status === 'active')
                                <span class="text-xs text-stone-400">freigeschaltet</span>
                            @else
                                <span class="text-xs text-stone-400">wartet auf Bestätigung</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-stone-400">
                            Keine Billing-Anfragen vorhanden.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->hasPages())
        <div class="mt-4">{{ $requests->links() }}</div>
    @endif

</div>
@endsection

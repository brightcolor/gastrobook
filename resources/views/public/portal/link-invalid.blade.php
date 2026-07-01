@extends('layouts.public', ['tenant' => $tenant ?? null])
@section('title', 'Link ungültig')
@section('content')
@php($du = \App\Models\Location::where('tenant_id', ($tenant ?? null)?->id)->first()?->effectiveSettings()->du() ?? false)
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">⏳</div>
    <h1 class="mt-3 text-2xl font-bold">Link ungültig oder abgelaufen</h1>
    <p class="mt-2 text-stone-600">Bitte {{ $du ? 'fordere' : 'fordern Sie' }} einen neuen Link an.</p>
    @if($tenant ?? null)
        <a href="{{ route('guest.portal.request', $tenant->slug) }}" class="btn-brand mt-5 inline-block rounded-xl px-5 py-3 font-bold text-white">Neuen Link anfordern</a>
    @endif
</div>
@endsection

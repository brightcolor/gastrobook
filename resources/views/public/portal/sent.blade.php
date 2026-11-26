@extends('layouts.public', ['tenant' => $tenant])
@section('title', 'Link gesendet')
@section('content')
@php($du = \App\Models\Location::where('tenant_id', $tenant?->id)->first()?->effectiveSettings()->du() ?? false)
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">📧</div>
    <h1 class="mt-3 text-2xl font-bold">Bitte E-Mails prüfen</h1>
    <p class="mt-2 text-stone-600">
        Falls ein Konto zu dieser E-Mail-Adresse existiert, haben wir {{ $du ? 'dir' : 'Ihnen' }} einen Anmeldelink geschickt.
        Der Link ist {{ \App\Support\Duration::minutes((int) config('swayy.guest_auth.login_minutes', 60)) }} gültig.
    </p>
    <x-spam-hint :du="$du" class="text-stone-500" />
</div>
@endsection

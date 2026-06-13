@extends('layouts.public', ['tenant' => $tenant])
@section('title', 'Mein Konto – ' . $tenant->name)
@section('content')
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-center text-2xl font-bold">Mein Konto</h1>
    <p class="mt-2 text-center text-sm text-stone-600">
        Geben Sie Ihre E-Mail-Adresse ein – wir senden Ihnen einen Anmeldelink (kein Passwort nötig).
    </p>
    <form method="POST" action="{{ route('guest.portal.link', $tenant->slug) }}" class="mt-6 space-y-4">
        @csrf
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <input type="email" name="email" required placeholder="ihre@email.de"
               class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
        @error('email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <button type="submit" class="btn-brand w-full rounded-xl py-3.5 text-lg font-bold text-white shadow hover:opacity-90">
            Anmeldelink senden
        </button>
    </form>
</div>
@endsection

@extends('layouts.public', ['tenant' => $tenant])
@section('title', 'Link gesendet')
@section('content')
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">📧</div>
    <h1 class="mt-3 text-2xl font-bold">Bitte E-Mails prüfen</h1>
    <p class="mt-2 text-stone-600">
        Falls ein Konto zu dieser E-Mail-Adresse existiert, haben wir Ihnen einen Anmeldelink geschickt.
        Der Link ist 60 Minuten gültig.
    </p>
</div>
@endsection

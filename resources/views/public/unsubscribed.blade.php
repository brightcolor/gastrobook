@extends('layouts.public', ['tenant' => null])
@section('title', 'Abgemeldet')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">✅</div>
    @if($alreadyGone)
        <h1 class="mt-3 text-2xl font-bold">Erledigt</h1>
        <p class="mt-2 text-stone-600">Für diese Adresse sind keine Werbenachrichten mehr hinterlegt.</p>
    @else
        <h1 class="mt-3 text-2xl font-bold">Abgemeldet{{ $name ? ', '.$name : '' }}</h1>
        <p class="mt-2 text-stone-600">
            An diese Adresse gehen keine Werbenachrichten mehr. Bestätigungen und
            Erinnerungen zu gebuchten Terminen sind davon nicht betroffen – die
            gehören zur Buchung.
        </p>
    @endif
</div>
@endsection

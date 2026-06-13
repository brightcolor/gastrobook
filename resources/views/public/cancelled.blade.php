@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Storniert')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">👋</div>
    <h1 class="mt-3 text-2xl font-bold">Reservierung storniert</h1>
    <p class="mt-2 text-stone-600">Schade, dass es nicht klappt. Wir hoffen, Sie bald bei uns begrüßen zu dürfen!</p>

    @if(($refund ?? null) !== null)
        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">
            @if($refund->status === 'completed')
                💶 Ihre Anzahlung von <strong>{{ $refund->amountFormatted() }}</strong> wurde zurückerstattet.
            @elseif(in_array($refund->status, ['approved', 'processing']))
                💶 Ihre Anzahlung von <strong>{{ $refund->amountFormatted() }}</strong> wird in Kürze zurückerstattet.
            @else
                💶 Ihre Rückerstattung von <strong>{{ $refund->amountFormatted() }}</strong> wird geprüft und anschließend bearbeitet.
            @endif
        </div>
    @endif

    <p class="mt-4 font-semibold">{{ $location->name }}</p>
</div>
@endsection

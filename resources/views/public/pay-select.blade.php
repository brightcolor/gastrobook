@extends('layouts.public')
@section('title', 'Zahlungsart wählen')
@section('content')
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-center text-2xl font-bold">Zahlungsart wählen</h1>
    <p class="mt-2 text-center text-stone-600">Zu zahlen: <strong>{{ $amount }}</strong></p>

    <div class="mt-6 space-y-3">
        @foreach($options as $opt)
            <a href="{{ $opt['url'] }}"
               class="btn-brand block rounded-xl py-4 text-center text-lg font-bold text-white shadow hover:opacity-90">
                @if($opt['key'] === 'paypal')🅿️ @else 💳 @endif {{ $opt['label'] }}
            </a>
        @endforeach
    </div>

    <a href="{{ $cancel_url }}" class="mt-6 block text-center text-sm text-stone-500 underline">Abbrechen</a>
</div>
@endsection

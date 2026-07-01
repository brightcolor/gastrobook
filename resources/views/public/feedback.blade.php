@extends('layouts.public', ['tenant' => $location?->tenant])
@section('title', 'Feedback')
@section('content')
@php($du = $location?->effectiveSettings()->du() ?? false)
<div class="rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-center text-2xl font-bold">Wie war {{ $du ? 'dein' : 'Ihr' }} Besuch?</h1>
    @if($location)<p class="mt-1 text-center text-stone-600">{{ $location->name }}</p>@endif

    <form method="POST" action="{{ route('feedback.store', ['token' => $feedbackRequest->token]) }}" class="mt-6 space-y-5">
        @csrf
        <div class="flex justify-center gap-2" id="stars">
            @for($i = 1; $i <= 5; $i++)
                <label class="cursor-pointer text-4xl grayscale transition has-[:checked]:grayscale-0">
                    <input type="radio" name="score" value="{{ $i }}" class="sr-only" required>⭐
                </label>
            @endfor
        </div>
        <div>
            <label for="comment" class="mb-1 block text-sm font-semibold">{{ $du ? 'Möchtest du' : 'Möchten Sie' }} uns etwas mitteilen? (optional)</label>
            <textarea name="comment" id="comment" rows="4" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3"></textarea>
        </div>
        <button class="btn-brand w-full rounded-xl py-3 font-bold text-white">Feedback senden</button>
    </form>
</div>
<script>
    document.querySelectorAll('#stars label').forEach((label, idx, all) => {
        label.querySelector('input').addEventListener('change', () => {
            all.forEach((l, i) => l.classList.toggle('grayscale', i > idx));
        });
    });
</script>
@endsection

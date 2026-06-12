@extends('layouts.marketing')

@section('title', 'Kontakt – GastroBook')

@section('content')
<section class="mx-auto max-w-xl px-4 py-16">
    <h1 class="text-3xl font-extrabold">Kontakt</h1>
    <p class="mt-3 text-stone-500">Fragen zu Tarifen, Enterprise-Angeboten oder zur Einrichtung? Schreiben Sie uns – wir antworten in der Regel innerhalb eines Werktags.</p>

    <form method="POST" action="{{ route('contact.send') }}" class="mt-8 space-y-4">
        @csrf
        <input type="text" name="website" value="" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

        @if($errors->any())
            <div class="rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
        @endif

        <div>
            <label for="name" class="mb-1 block text-sm font-semibold">Name</label>
            <input type="text" name="name" id="name" required maxlength="120" value="{{ old('name') }}"
                   class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
        </div>
        <div>
            <label for="email" class="mb-1 block text-sm font-semibold">E-Mail</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
        </div>
        <div>
            <label for="message" class="mb-1 block text-sm font-semibold">Nachricht</label>
            <textarea name="message" id="message" required rows="6" maxlength="5000"
                      class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('message') }}</textarea>
        </div>
        <button class="rounded-xl bg-teal-700 px-8 py-3 font-bold text-white hover:bg-teal-800">Absenden</button>
    </form>
</section>
@endsection

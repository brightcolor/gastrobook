@extends('layouts.admin')
@section('title', 'E-Mail-Vorlagen')
@section('content')

<h1 class="mb-1 text-2xl font-bold">E-Mail-Vorlagen</h1>
<p class="mb-5 text-sm text-stone-500">Passe Betreff und Text der automatischen E-Mails an. Leer/Standard = die eingebaute Vorlage wird verwendet.</p>

<div class="mb-5 rounded-2xl bg-stone-50 p-4 text-sm ring-1 ring-stone-100">
    <p class="mb-2 font-semibold text-stone-700">Verfügbare Platzhalter</p>
    <div class="flex flex-wrap gap-1.5">
        @foreach($placeholders as $ph)
            <code class="rounded bg-white px-2 py-0.5 text-xs text-stone-600 ring-1 ring-stone-200">{{ '{'.$ph.'}' }}</code>
        @endforeach
    </div>
    <p class="mt-2 text-xs text-stone-400">Platzhalter werden beim Versand durch die echten Werte ersetzt. E-Mails werden als reiner Text versendet.</p>
</div>

<div class="space-y-3">
    @foreach($templates as $t)
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <details @if($loop->first) open @endif>
                <summary class="flex cursor-pointer items-center justify-between gap-3">
                    <span class="font-bold">
                        {{ $t['label'] }}
                        @if($t['customized'])
                            <span class="ml-2 rounded-full bg-teal-100 px-2 py-0.5 text-xs font-semibold text-teal-700">angepasst</span>
                        @else
                            <span class="ml-2 rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-500">Standard</span>
                        @endif
                    </span>
                    <code class="shrink-0 text-xs text-stone-400">{{ $t['key'] }}</code>
                </summary>

                <form method="POST" action="{{ route('admin.templates.update', $t['key']) }}" class="mt-4 space-y-3 text-sm">
                    @csrf @method('PUT')
                    <label class="block">Betreff
                        <input name="subject" required value="{{ $t['subject'] }}" class="mt-1 w-full rounded-lg border-stone-200">
                    </label>
                    <label class="block">Text
                        <textarea name="body" required rows="9" class="mt-1 w-full rounded-lg border-stone-200 font-mono text-xs leading-relaxed">{{ $t['body'] }}</textarea>
                    </label>
                    <button class="rounded-xl bg-stone-900 px-5 py-2 font-bold text-white">Speichern</button>
                </form>
                @if($t['customized'])
                    <form method="POST" action="{{ route('admin.templates.reset', $t['key']) }}" class="mt-2"
                          onsubmit="return confirm('Diese Vorlage auf den Standard zurücksetzen?')">
                        @csrf @method('DELETE')
                        <button class="text-xs font-semibold text-stone-500 hover:text-red-600">Auf Standard zurücksetzen</button>
                    </form>
                @endif
            </details>
        </div>
    @endforeach
</div>
@endsection

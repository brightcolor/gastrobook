@props(['id', 'title'])
{{-- Einstellung als Karte mit Erklärung: ab 1024 px links Titel und Erklärung, rechts die Karte.
     grid-cols-1 und min-w-0 halten die Spalten in Bildschirmbreite, auch bei langen Codezeilen.
     Der Slot "aside" (etwa eine Vorschau) steht unter der Karte und rückt ab 1024 px in die linke Spalte unter die Erklärung,
     wo er beim Scrollen mitläuft; die Karte reicht dann über beide Zeilen. --}}
<section id="{{ $id }}" aria-labelledby="{{ $id }}-title"
         class="grid grid-cols-1 gap-4 border-t border-stone-200 py-8 first-of-type:border-t-0 first-of-type:pt-2 lg:grid-cols-3 lg:gap-8{{ isset($aside) ? ' lg:grid-rows-[auto_1fr]' : '' }}">
    <div>
        <h2 id="{{ $id }}-title" class="text-base font-semibold">{{ $title }}</h2>
        @isset($description)
            <div class="mt-1.5 space-y-2 text-sm text-stone-600">{{ $description }}</div>
        @endisset
    </div>
    <div class="min-w-0 lg:col-span-2{{ isset($aside) ? ' lg:row-span-2' : '' }}">
        <div class="rounded-xl border border-stone-200 bg-white shadow-[0_1px_2px_rgba(28,25,23,0.04)]">
            <div class="space-y-5 px-5 py-5 sm:px-6">{{ $slot }}</div>
        </div>
    </div>
    @isset($aside)
        <div class="min-w-0 lg:sticky lg:top-20 lg:col-start-1 lg:row-start-2 lg:self-start">{{ $aside }}</div>
    @endisset
</section>

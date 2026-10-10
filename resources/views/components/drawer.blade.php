@props(['id', 'title', 'description' => null])
{{-- Seitenleiste von rechts zum Anlegen und Bearbeiten (resources/js/admin/drawer.js), auf dem Smartphone bildschirmfüllend.
     Seiten legen sie mit @push('drawers') ans Ende des Dokuments. Geöffnet von data-drawer-open="{id}" oder der Adresse #{id}.
     Knöpfe im Slot "footer" verweisen mit form="…" auf das Formular im Inhalt. --}}
<div id="{{ $id }}" data-drawer hidden class="fixed inset-0 z-50">
    <div data-drawer-close data-drawer-backdrop class="absolute inset-0 bg-stone-900/40" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif
         class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl md:max-w-md">
        <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
            <div class="min-w-0">
                <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
                @if($description)
                    <p id="{{ $id }}-description" class="mt-1 text-sm text-stone-600">{{ $description }}</p>
                @endif
            </div>
            <button type="button" data-drawer-close aria-label="Schließen"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-2xl leading-none text-stone-600 hover:bg-stone-100 hover:text-stone-900">×</button>
        </div>
        <div data-drawer-body class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex flex-wrap justify-end gap-2 border-t border-stone-200 px-5 py-4">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>

{{-- Code zum Kopieren; resources/js/admin/widget.js füllt ihn.
     $kind: popup, inline oder link. $message: Hinweis nach dem Kopieren. $name: Bezeichnung für Screenreader am Knopf. --}}
<div class="space-y-2">
    <pre data-widget-snippet="{{ $kind }}" class="whitespace-pre-wrap rounded-md bg-stone-50 p-3 text-xs text-stone-800 ring-1 ring-stone-200 [overflow-wrap:anywhere]"></pre>
    <button type="button" data-copy="" data-widget-copy="{{ $kind }}" data-copy-message="{{ $message }}" class="btn btn-secondary">Code kopieren<span class="sr-only"> ({{ $name }})</span></button>
</div>

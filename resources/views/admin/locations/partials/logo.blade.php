{{-- Logo eines Standorts: eigenes Formular, speichert sofort (LocationLogoController). --}}
<section aria-labelledby="logo-{{ $loc->id }}-title" class="space-y-4 border-t border-stone-200 pt-5">
    <h3 id="logo-{{ $loc->id }}-title" class="text-sm font-semibold">Logo</h3>
    @if($loc->brand_logo_path)
        <img src="{{ route('brand.location.logo', [$tenant->slug, $loc->slug]) }}?v={{ $loc->updated_at?->timestamp }}"
             alt="Aktuelles Logo von {{ $loc->name }}" class="h-16 w-auto max-w-full rounded border border-stone-200 bg-white p-1">
    @else
        <p class="text-sm text-stone-600">Ohne Logo zeigt die Buchungsseite den Namen des Standorts.</p>
    @endif
    <form method="POST" action="{{ route('admin.locations.logo.store', $loc) }}" enctype="multipart/form-data" data-ajax class="space-y-3">
        @csrf
        <x-field name="logo" label="Neues Logo" type="file" :id="'logo-'.$loc->id.'-file'" :accept="$logoAccept" :help="$logoHelp" required />
        <button type="submit" class="btn btn-secondary">Logo hochladen</button>
    </form>
    @if($loc->brand_logo_path)
        <x-inline-confirm id="logo-entfernen-{{ $loc->id }}" :action="route('admin.locations.logo.destroy', $loc)" method="DELETE"
                          label="Logo entfernen" confirm="Logo entfernen" :subject="$loc->name"
                          :question="'Logo von „'.$loc->name.'“ entfernen? Die Buchungsseite zeigt dann den Namen.'" />
    @endif
</section>

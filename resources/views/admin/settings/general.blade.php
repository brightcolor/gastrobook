@extends('layouts.admin')
@section('title', 'Allgemein')

@section('content')
@use('App\Enums\TenantType')
<x-save-bar />
<x-page-header title="Allgemein" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Name, Art und Auftritt deines Betriebs." />

<x-settings.section id="betrieb" title="Betrieb">
    <x-slot:description>
        <p>Der Name erscheint in der Verwaltung, in Mails an Gäste und auf der Buchungsseite.</p>
        <p>Die drei Links führen Gäste auf der Buchungsseite zu deinen Rechtstexten. Die Datenschutzhinweise bestätigt ein Gast vor dem Buchen.</p>
    </x-slot:description>
    <form id="formGeneral" method="POST" action="{{ route('admin.settings.general') }}" data-ajax data-savebar class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="business_name" label="Name des Betriebs" :value="$tenant->name" required maxlength="120" />
        <x-field name="imprint_url" label="Impressum" type="url" :value="$tenant->imprint_url" placeholder="https://dein-betrieb.de/impressum" />
        <x-field name="privacy_url" label="Datenschutzhinweise" type="url" :value="$tenant->privacy_url" placeholder="https://dein-betrieb.de/datenschutz"
                 help="Gäste bestätigen diese Hinweise vor dem Buchen." />
        <x-field name="terms_url" label="AGB" type="url" :value="$tenant->terms_url" placeholder="https://dein-betrieb.de/agb" />
    </form>
</x-settings.section>

<x-settings.section id="betriebstyp" title="Betriebstyp">
    <x-slot:description>
        <p>Restaurants arbeiten mit Räumen, Tischen und Reservierungen, Salons mit Leistungen, Mitarbeitern und Terminen. Ein Wechsel passt Navigation und Buchungsseite sofort an.</p>
    </x-slot:description>
    <form id="formType" method="POST" action="{{ route('admin.settings.tenant-type') }}" data-ajax data-savebar>
        @csrf
        @method('PUT')
        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="sr-only">Betriebstyp</legend>
            @foreach(TenantType::cases() as $type)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-teal-700 has-[:checked]:ring-1 has-[:checked]:ring-teal-700 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-teal-700">
                    <input type="radio" name="type" value="{{ $type->value }}" @checked($tenant->type === $type) class="mt-1">
                    <span>
                        <span class="block font-semibold">{{ $type->label() }}</span>
                        <span class="block text-[13px] text-stone-500">{{ $type->summary() }}</span>
                    </span>
                </label>
            @endforeach
        </fieldset>
    </form>
</x-settings.section>

<x-settings.section id="markenfarbe" title="Markenfarbe">
    <x-slot:description>
        <p>Die Farbe erscheint auf der Buchungsseite und auf dem Buchungsknopf für deine Website. Am besten die Hauptfarbe deines Logos.</p>
    </x-slot:description>
    <form id="formBrand" method="POST" action="{{ route('admin.settings.branding') }}" data-ajax data-savebar>
        @csrf
        @method('PUT')
        <x-field name="brand_primary_color" label="Farbe" help="Ein Klick auf das Farbfeld öffnet die Auswahl.">
            <input id="f-brand_primary_color" type="color" name="brand_primary_color" value="{{ old('brand_primary_color', $brandColor) }}"
                   @error('brand_primary_color') aria-invalid="true" aria-describedby="f-brand_primary_color-help f-brand_primary_color-error" @else aria-describedby="f-brand_primary_color-help" @enderror
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </form>
</x-settings.section>
@endsection

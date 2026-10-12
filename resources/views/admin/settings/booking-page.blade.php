@extends('layouts.admin')
@section('title', 'Buchungsseite & Widget')

@section('content')
@php
    $colorHelp = 'Vorgabe ist deine Markenfarbe. Die Schrift ist weiß; dunkle bis mittlere Farben lesen sich am besten.';
    $formPage = $salon ? 'Termin buchen' : 'Tisch reservieren';
@endphp
<x-save-bar />
<x-page-header title="Buchungsseite & Widget" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               description="Wie Gäste zu dir finden: über deine Buchungsseite oder einen Knopf auf deiner Website.">
    <x-slot:actions>
        @if($acceptsBookings)
            <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="btn btn-secondary">Buchungsseite ansehen<span class="sr-only"> (öffnet in einem neuen Tab)</span></a>
        @endif
    </x-slot:actions>
</x-page-header>

<x-settings.section id="buchungsseite" title="Buchungsseite">
    <x-slot:description>
        <p>Diese Adresse teilst du überall: auf Instagram, bei Google oder in einer Mail.</p>
        @if($severalLocations)
            <p>Die Adresse führt zum Standort „{{ $location->name }}“. Unter {{ $landingUrl }} wählen Gäste zuerst den Standort.</p>
        @endif
    </x-slot:description>
    @unless($acceptsBookings)
        <p role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {{ $location->name }} nimmt gerade keine Online-Buchungen an.
            @if($location->is_active)
                Bitte frag den Support, damit er die Online-Buchung für diesen Standort einschaltet.
            @else
                Aktivieren kannst du den Standort unter <a href="{{ route('admin.locations.index') }}" class="font-semibold underline underline-offset-2">Standorte</a>.
            @endif
        </p>
    @endunless
    <div class="flex flex-wrap items-center gap-2">
        <code class="min-w-0 flex-1 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-800 ring-1 ring-stone-200 [overflow-wrap:anywhere]">{{ $bookingUrl }}</code>
        <button type="button" data-copy="{{ $bookingUrl }}" data-copy-message="Adresse der Buchungsseite kopiert." class="btn btn-secondary">Adresse kopieren</button>
    </div>
    <form id="formBookingPage" method="POST" action="{{ route('admin.settings.booking-page') }}" data-ajax data-savebar class="space-y-4 border-t border-stone-200 pt-5">
        @csrf
        @method('PUT')
        @unless($salon)
            <x-checkbox name="public_floorplan_enabled" label="Tischplan zeigen" :checked="(bool) $settings->public_floorplan_enabled"
                        help="Gäste sehen den Grundriss und wählen ihren Tisch selbst." />
        @endunless
        <x-checkbox name="confetti_on_booking" label="Konfetti nach dem Buchen" :checked="(bool) $settings->confetti_on_booking"
                    help="Ein kurzer Konfettiregen auf der Bestätigungsseite, sobald die Buchung bestätigt ist." />
    </form>
</x-settings.section>

{{-- Einstellungen für resources/js/admin/widget.js --}}
<div data-widget-builder hidden data-popup-src="{{ $popupSrc }}" data-embed-src="{{ $embedSrc }}"
     data-booking-url="{{ $bookingUrl }}" data-default-label="{{ $defaultLabel }}" data-default-color="{{ $brandColor }}"></div>

<x-settings.section id="popup" title="Knopf mit Buchungsfenster">
    <x-slot:description>
        <p>Ein Knopf auf deiner Website öffnet das Buchungsformular als Fenster über der Seite. Das passt zu jeder Website.</p>
        <p>Füge den Code in den HTML-Bereich deiner Seite ein, etwa in WordPress, Squarespace oder Webflow. Der Knopf erscheint an dieser Stelle. Ein schwebender Knopf sitzt unten rechts, dann kann der Code überall stehen, etwa vor dem schließenden &lt;/body&gt;.</p>
    </x-slot:description>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="widget_label" id="w-label" label="Text auf dem Knopf" :value="$defaultLabel" :maxlength="$labelMax" autocomplete="off" data-widget-input="label" />
        <x-field name="widget_color" id="w-color" label="Farbe des Knopfs" :help="$colorHelp">
            <input id="w-color" name="widget_color" type="color" value="{{ $brandColor }}" data-widget-input="color" aria-describedby="w-color-help"
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </div>
    <x-checkbox name="widget_float" id="w-float" label="Knopf schwebt unten rechts" help="Er bleibt beim Scrollen sichtbar." data-widget-input="float" />
    @include('admin.settings.partials.widget-code', ['kind' => 'popup', 'message' => 'Code für den Knopf kopiert.', 'name' => 'Knopf mit Buchungsfenster'])
</x-settings.section>

<x-settings.section id="eingebettet" title="Eingebettetes Formular">
    <x-slot:description>
        <p>Das Buchungsformular erscheint direkt auf deiner Seite, etwa auf einer Unterseite „{{ $formPage }}“.</p>
        <p>Es erscheint an der Stelle von &lt;div id="swayy-widget"&gt;.</p>
    </x-slot:description>
    @include('admin.settings.partials.widget-code', ['kind' => 'inline', 'message' => 'Code für das Formular kopiert.', 'name' => 'Eingebettetes Formular'])
</x-settings.section>

<x-settings.section id="direktlink" title="Einfacher Link">
    <x-slot:description>
        <p>Ein gestalteter Link zur Buchungsseite. Er besteht aus reinem HTML und passt in jeden Baukasten.</p>
    </x-slot:description>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="widget_link_label" id="w-link-label" label="Text des Links" :value="$defaultLabel" :maxlength="$labelMax" autocomplete="off" data-widget-input="link-label" />
        <x-field name="widget_link_color" id="w-link-color" label="Farbe des Links" :help="$colorHelp">
            <input id="w-link-color" name="widget_link_color" type="color" value="{{ $brandColor }}" data-widget-input="link-color" aria-describedby="w-link-color-help"
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </div>
    @include('admin.settings.partials.widget-code', ['kind' => 'link', 'message' => 'Code für den Link kopiert.', 'name' => 'Einfacher Link'])
</x-settings.section>
@endsection

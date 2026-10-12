@use('App\Support\ErrorPage')
{{-- Wege zurück. Angemeldet zuerst die Übersicht der Verwaltung, dann die Startseite.
     $overview: „Zur Übersicht“ anbieten (Vorgabe: angemeldet). $logout: zusätzlich „Abmelden“, wenn jemand angemeldet ist.
     $primary: Der erste Knopf dieser Gruppe ist der Hauptknopf (Vorgabe: ja); die Seite setzt ihn auf nein, wenn sie vorher
     einen eigenen Hauptknopf zeigt. --}}
@php
    $signedIn = ErrorPage::signedIn();
    $showOverview = $signedIn && ($overview ?? true);
@endphp
@if($showOverview)
    <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">Zur Übersicht</a>
@endif
<a href="{{ url('/') }}" class="btn {{ $showOverview || ! ($primary ?? true) ? 'btn-secondary' : 'btn-primary' }}">Zur Startseite</a>
@if($signedIn && ($logout ?? false))
    <a href="{{ route('logout.confirm') }}" class="btn btn-secondary">Abmelden</a>
@endif

@use('App\Support\ErrorPage')
{{-- Wege zurück. Angemeldet zuerst die Übersicht der Verwaltung, dann die Startseite.
     $overview: „Zur Übersicht“ anbieten (Vorgabe: angemeldet). $logout: zusätzlich „Abmelden“, wenn jemand angemeldet ist. --}}
@php
    $signedIn = ErrorPage::signedIn();
    $showOverview = $signedIn && ($overview ?? true);
@endphp
@if($showOverview)
    <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">Zur Übersicht</a>
@endif
<a href="{{ url('/') }}" class="btn {{ $showOverview ? 'btn-secondary' : 'btn-primary' }}">Zur Startseite</a>
@if($signedIn && ($logout ?? false))
    <a href="{{ route('logout.confirm') }}" class="btn btn-secondary">Abmelden</a>
@endif

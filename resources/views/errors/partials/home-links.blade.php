@use('App\Support\ErrorPage')
{{-- Wege zurück: angemeldet zur Übersicht der Verwaltung, sonst zur Startseite. --}}
@if(ErrorPage::signedIn())
    <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">Zur Übersicht</a>
    <a href="{{ url('/') }}" class="btn btn-secondary">Zur Startseite</a>
@else
    <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
@endif

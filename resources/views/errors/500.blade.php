@extends('errors.layout')
@use('App\Support\ErrorPage')

@section('code', '500')
@section('title', 'Da ist etwas schiefgegangen')
@section('cause', 'Swayy konnte die Anfrage gerade nicht ausführen. Der Fehler ist protokolliert.')
@section('step')
    Bitte lade die Seite neu. Tritt der Fehler wieder auf, schreib uns an
    <a href="mailto:{{ ErrorPage::supportEmail() }}" class="font-semibold underline underline-offset-2">{{ ErrorPage::supportEmail() }}</a>.
@endsection

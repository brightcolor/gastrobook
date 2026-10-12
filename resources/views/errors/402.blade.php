@extends('errors.layout')
@use('App\Support\ErrorPage')

{{-- 402 sperrt den Zugang eines Betriebs (EnsureTrialActive). „Zur Übersicht“ führt wieder in diese Seite, deshalb fehlt
     der Knopf; Startseite und Abmelden bleiben. Plattform-Administratoren kommen über den Supportzugriff hierher und
     finden mit „Zur Plattform“ zurück zu den Betrieben. --}}
@php
    $reason = ErrorPage::reason($exception ?? null);
@endphp

@section('code', '402')
@section('title', 'Dieser Betrieb ist gesperrt')
@section('cause', $reason !== '' ? $reason : __('Der Zugang dieses Betriebs ist gerade gesperrt. Schreib uns an :email, dann klären wir den Grund.', ['email' => ErrorPage::supportEmail()]))
@section('step')
    @if(ErrorPage::signedIn())
        Frag den Inhaber deines Betriebs oder melde dich mit einem anderen Konto an.
        @if(ErrorPage::platformAdmin())
            Als Plattform-Administrator änderst du den Zustand des Betriebs in der Plattform unter „Betriebe“.
        @endif
    @else
        Wende dich an den Betrieb.
    @endif
@endsection
@section('actions')
    @if(ErrorPage::platformAdmin())
        <a href="{{ url('/saas') }}" class="btn btn-primary">Zur Plattform</a>
    @endif
    @include('errors.partials.home-links', ['overview' => false, 'logout' => true, 'primary' => ! ErrorPage::platformAdmin()])
@endsection

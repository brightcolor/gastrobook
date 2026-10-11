@extends('errors.layout')
@use('App\Support\ErrorPage')

{{-- 402 sperrt den Zugang eines Betriebs (EnsureTrialActive). „Zur Übersicht“ führt wieder in diese Seite, deshalb fehlt
     der Knopf; Startseite und Abmelden bleiben. --}}
@php
    $reason = ErrorPage::reason($exception ?? null);
@endphp

@section('code', '402')
@section('title', 'Dieser Betrieb ist gesperrt')
@section('cause', $reason !== '' ? $reason : __('Der Zugang dieses Betriebs ist gerade gesperrt. Schreib uns an :email, dann klären wir den Grund.', ['email' => ErrorPage::supportEmail()]))
@section('step', ErrorPage::signedIn() ? 'Frag den Inhaber deines Betriebs oder melde dich mit einem anderen Konto an.' : 'Wende dich an den Betrieb.')
@section('actions')
    @include('errors.partials.home-links', ['overview' => false, 'logout' => true])
@endsection

@extends('errors.layout')
@use('App\Support\ErrorPage')

{{-- Sperrt die Verwaltung einer selbst betriebenen Installation (RequireValidLicense, Status 402): Die Lizenz fehlt, ist
     abgelaufen oder widerrufen. Die Buchungsseiten bleiben offen. „Zur Übersicht“ führt wieder in diese Seite, deshalb fehlt
     der Knopf; Startseite und Abmelden bleiben. Die Adresse für den Support kommt aus swayy.support.email. --}}
@php
    $support = ErrorPage::supportEmail();
@endphp

@section('code', '402')
@section('title', $status->revoked ? 'Lizenz widerrufen' : 'Lizenz abgelaufen')
@section('cause')
    @if($status->revoked)
        Diese Swayy-Lizenz wurde widerrufen.
    @else
        Die Swayy-Lizenz für diese Installation ist abgelaufen.
    @endif
    @if($status->licenseId)
        <span class="mt-2 block font-mono text-xs text-stone-600">Lizenz-ID: {{ $status->licenseId }}</span>
    @endif
@endsection
@section('step')
    Schreib uns an
    <a href="mailto:{{ $support }}" class="font-semibold underline underline-offset-2">{{ $support }}</a>,
    dann klären wir die Erneuerung. Die Buchungsseiten sind weiterhin erreichbar.
@endsection
@section('actions')
    <a href="mailto:{{ $support }}" class="btn btn-primary">Lizenz erneuern</a>
    @include('errors.partials.home-links', ['overview' => false, 'logout' => true])
@endsection

@extends('errors.layout')
@use('App\Support\ErrorPage')

{{-- Drei Fälle. Ein Grund aus abort() steht unter einem neutralen Titel (gesperrter Betrieb, Konto ohne Betrieb).
     Ohne Grund in der Verwaltung fehlt der Rolle das Recht. Ohne Grund an einer öffentlichen Adresse (Link aus
     einer Mail, Rückkehr vom Zahlungsanbieter) geht es um den Link und um keine Rolle. --}}
@php
    $reason = ErrorPage::reason($exception ?? null);
    $kind = $reason !== '' ? 'reason' : (ErrorPage::adminArea() && ErrorPage::signedIn() ? 'role' : 'link');
@endphp

@section('code', '403')
@section('title', match ($kind) {
    'reason' => 'Zugriff nicht möglich',
    'role' => 'Dafür fehlt dir das Recht',
    default => 'Dieser Link funktioniert nicht (mehr)',
})
@section('cause', match ($kind) {
    'reason' => $reason,
    'role' => 'Diese Seite ist deiner Rolle nicht freigegeben.',
    default => 'Er ist abgelaufen, wurde verändert oder gehört zu einem anderen Vorgang.',
})
@section('step', match (true) {
    $kind === 'role' => 'Frag den Inhaber oder die Administration deines Betriebs.',
    $kind === 'reason' && ErrorPage::signedIn() => 'Frag den Inhaber deines Betriebs oder melde dich mit einem anderen Konto an.',
    $kind === 'reason' => 'Wende dich an den Betrieb, wenn du so nicht weiterkommst.',
    default => 'Öffne den Link noch einmal aus deiner E-Mail oder wende dich an den Betrieb.',
})
@section('actions')
    @include('errors.partials.home-links', ['overview' => ErrorPage::overviewReachable(), 'logout' => true])
@endsection

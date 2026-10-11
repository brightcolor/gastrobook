@extends('errors.layout')
@use('App\Support\ErrorPage')

@section('code', '403')
@section('title', 'Dafür fehlt dir das Recht')
@section('cause', ErrorPage::reason($exception ?? null) ?: 'Diese Seite ist deiner Rolle nicht freigegeben.')
@section('step', 'Frag den Inhaber oder die Administration deines Betriebs.')
@section('actions')
    @if(ErrorPage::signedIn())
        <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">Zur Übersicht</a>
    @else
        <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
    @endif
@endsection

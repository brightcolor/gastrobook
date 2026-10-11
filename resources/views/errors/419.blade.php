@extends('errors.layout')
@use('App\Support\ErrorPage')

@section('code', '419')
@section('title', 'Die Seite war zu lange offen')
@section('cause', 'Aus Sicherheitsgründen gilt ein Formular nur eine Weile.')
@section('step', 'Bitte lade die Seite neu und sende das Formular noch einmal.')
@section('actions')
    <a href="{{ ErrorPage::reloadUrl() }}" class="btn btn-primary">Seite neu laden</a>
@endsection

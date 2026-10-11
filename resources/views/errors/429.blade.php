@extends('errors.layout')
@use('App\Support\ErrorPage')

@section('code', '429')
@section('title', 'Zu viele Anfragen')
@section('cause', 'In kurzer Zeit kamen sehr viele Anfragen von deinem Anschluss.')
@section('step', ($wait = ErrorPage::waitTime($exception ?? null)) !== null
    ? 'Bitte warte '.$wait.' und versuch es dann erneut.'
    : 'Bitte warte einen Moment und versuch es dann erneut.')

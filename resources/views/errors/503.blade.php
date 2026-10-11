@extends('errors.layout')
@use('App\Support\ErrorPage')

@section('code', '503')
@section('title', 'Swayy wird gerade aktualisiert')
@section('cause', ErrorPage::reason($exception ?? null) ?: 'Gleich ist alles wieder da.')
@section('step', 'Bitte versuch es in ein paar Minuten noch einmal.')

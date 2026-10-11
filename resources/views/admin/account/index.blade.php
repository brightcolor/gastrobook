@extends('layouts.admin')
@section('title', 'Mein Konto')

@section('content')
<x-page-header title="Mein Konto" description="Deine persönlichen Angaben und dein Zugang zu Swayy." />

<x-settings.section id="profil" title="Profil">
    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
        <dt class="font-semibold text-stone-600">Name</dt>
        <dd class="[overflow-wrap:anywhere]">{{ $user->name }}</dd>
        <dt class="font-semibold text-stone-600">E-Mail</dt>
        <dd class="[overflow-wrap:anywhere]">{{ $user->email }}</dd>
        <dt class="font-semibold text-stone-600">Konto seit</dt>
        <dd>{{ $user->created_at->format('d.m.Y') }}</dd>
    </dl>
    @if($isOwner && $tenant !== null)
        <p class="text-sm text-stone-600">
            Datenexport, Import und „Betrieb löschen“ für „{{ $tenant->name }}“ findest du unter
            <a href="{{ route('admin.settings.data.show') }}" class="font-semibold underline underline-offset-2">Einstellungen › Daten</a>.
        </p>
    @endif
</x-settings.section>

<x-settings.section id="passwort" title="Passwort">
    <x-slot:description>
        <p>Wähle ein Passwort, das nur für Swayy gilt. Nach dem Ändern bleibst du auf diesem Gerät angemeldet. Auf allen anderen Geräten meldest du dich mit dem neuen Passwort neu an.</p>
    </x-slot:description>
    <form id="form-passwort" method="POST" action="{{ route('admin.account.password') }}" data-ajax data-reset-after-save class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="current_password" label="Aktuelles Passwort" type="password" required autocomplete="current-password" />
        <x-field name="password" label="Neues Passwort" type="password" required autocomplete="new-password"
                 :help="'Mindestens '.$passwordMin.' Zeichen, mit Buchstaben und Ziffern.'" />
        <x-field name="password_confirmation" label="Neues Passwort wiederholen" type="password" required autocomplete="new-password" />
        <button type="submit" class="btn btn-primary">Passwort ändern</button>
    </form>
</x-settings.section>

<x-settings.section id="konto-loeschen" title="Konto löschen">
    <x-slot:description>
        <p>Entfernt deinen Zugang und deine Anmeldedaten. Buchungen und andere Daten des Betriebs bleiben erhalten.</p>
    </x-slot:description>
    @if($isLastOwner && $tenant !== null)
        <p class="text-sm text-stone-700">
            Du bist einziger Inhaber von „{{ $tenant->name }}“. Lösche zuerst den Betrieb unter
            <a href="{{ route('admin.settings.data.show') }}#betrieb-loeschen" class="font-semibold underline underline-offset-2">Einstellungen › Daten</a>
            oder mach unter <a href="{{ route('admin.users.index') }}" class="font-semibold underline underline-offset-2">Team & Rollen</a> eine andere Person zum Inhaber.
        </p>
    @else
        <x-inline-confirm id="konto-loeschen-frage" :action="route('admin.account.destroy')" method="DELETE" :ajax="false"
                          :open="$errors->has('confirm')"
                          label="Konto löschen …" confirm="Konto endgültig löschen"
                          question="Dein Konto endgültig löschen? Danach kannst du dich nicht mehr anmelden.">
            <x-field name="confirm" label="Zur Bestätigung LÖSCHEN eintippen" id="confirm-account" required autocomplete="off" />
        </x-inline-confirm>
    @endif
</x-settings.section>
@endsection

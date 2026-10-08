@extends('layouts.admin')
@section('title', 'Daten')

@section('content')
<x-page-header title="Daten" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Den ganzen Betrieb als Datei sichern, eine Sicherung einspielen oder den Betrieb löschen." />

<x-settings.section id="export" title="Alle Daten herunterladen">
    <x-slot:description>
        <p>Die Datei enthält Standorte, Räume und Tische, Zeiten, Reservierungen, Gäste, Events, Mitarbeiter, Leistungen und Einstellungen. Gut als Sicherung oder für einen Umzug.</p>
        <p>Zugangsdaten und Schlüssel für Zahlungsanbieter bleiben aus Sicherheitsgründen draußen.</p>
    </x-slot:description>
    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        Die Datei enthält personenbezogene Daten deiner Gäste. Bewahre sie sorgsam auf.
    </p>
    <a href="{{ route('admin.account.export') }}" class="btn btn-primary">Daten von „{{ $tenant->name }}“ herunterladen</a>
</x-settings.section>

<x-settings.section id="import" title="Daten einspielen">
    <x-slot:description>
        <p>Liest eine zuvor heruntergeladene Datei ein, etwa beim Umzug von einem anderen Swayy-System. Die Daten kommen zu den bestehenden dazu.</p>
        <p>Bestätigungslinks entstehen neu; bereits versendete Links zum Ändern oder Stornieren gelten danach nicht mehr. Offene Erstattungen gelten als erledigt, sie gehören ins alte System. Stripe und PayPal verbindest du danach neu.</p>
    </x-slot:description>
    <form method="POST" action="{{ route('admin.account.import') }}" enctype="multipart/form-data" class="space-y-5">
        @csrf
        <x-field name="file" label="Export-Datei" type="file" id="import-file" accept=".json,application/json" required
                 :help="'JSON-Datei aus „Daten herunterladen“, bis '.$importLimit.'.'" />
        <div class="space-y-1.5">
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="confirm" value="1" required class="mt-1"
                       @error('confirm') aria-invalid="true" aria-describedby="import-confirm-error" @enderror>
                <span>Ja, die Daten aus der Datei zu „{{ $tenant->name }}“ hinzufügen.</span>
            </label>
            @error('confirm')
                <p id="import-confirm-error" class="text-[0.8125rem] text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary">Datei einspielen</button>
    </form>
</x-settings.section>

<x-settings.section id="betrieb-loeschen" title="Betrieb löschen">
    <x-slot:description>
        <p>Löscht den Betrieb mit allen Standorten, Reservierungen, Gästedaten, Mitarbeitern und Einstellungen endgültig. Lade vorher die Daten herunter.</p>
    </x-slot:description>
    @if($isLastOwner)
        <x-inline-confirm id="betrieb-loeschen-frage" :action="route('admin.account.tenant.destroy')" method="DELETE" :ajax="false"
                          :open="$errors->has('confirm_tenant')"
                          label="Betrieb löschen …" confirm="Betrieb endgültig löschen"
                          :question="'„'.$tenant->name.'“ endgültig löschen? Danach meldet Swayy dich ab.'">
            <div class="space-y-1.5">
                <label for="confirm-tenant-name" class="block text-sm font-semibold text-stone-800">Zur Bestätigung den Namen des Betriebs eintippen</label>
                <input id="confirm-tenant-name" name="confirm" required autocomplete="off" class="w-full"
                       aria-describedby="confirm-tenant-name-help @error('confirm_tenant') confirm-tenant-name-error @enderror"
                       @error('confirm_tenant') aria-invalid="true" @enderror>
                <p id="confirm-tenant-name-help" class="text-[0.8125rem] text-stone-600">Genau so: {{ $tenant->name }}</p>
                @error('confirm_tenant')
                    <p id="confirm-tenant-name-error" class="text-[0.8125rem] text-red-700">{{ $message }}</p>
                @enderror
            </div>
        </x-inline-confirm>
    @else
        <p class="text-sm text-stone-700">
            Löschen kann den Betrieb nur, wer als einziger Inhaber übrig ist.
            Gib den anderen Inhabern unter <a href="{{ route('admin.users.index') }}" class="font-semibold underline underline-offset-2">Team & Rollen</a> zuerst eine andere Rolle.
        </p>
    @endif
</x-settings.section>
@endsection

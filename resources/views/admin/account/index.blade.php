@extends('layouts.admin')
@section('title', 'Mein Konto')
@section('content')

<h1 class="mb-6 text-2xl font-bold">Mein Konto</h1>

<div class="max-w-lg space-y-5">

    {{-- Profil-Info --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-3 font-bold">Profil</h2>
        <div class="space-y-1 text-sm text-stone-700">
            <p><span class="font-semibold text-stone-500">Name:</span> {{ $user->name }}</p>
            <p><span class="font-semibold text-stone-500">E-Mail:</span> {{ $user->email }}</p>
            <p><span class="font-semibold text-stone-500">Konto erstellt:</span> {{ $user->created_at->format('d.m.Y') }}</p>
        </div>
    </div>

    {{-- Betrieb löschen (nur für Inhaber) --}}
    @if($isLastOwner && $tenant !== null)
        <div class="rounded-2xl border-2 border-red-200 bg-red-50 p-5">
            <h2 class="mb-1 font-bold text-red-700">Betrieb löschen</h2>
            <p class="mb-4 text-sm text-red-600">
                Löscht <strong>„{{ $tenant->name }}"</strong> vollständig — alle Standorte, Reservierungen,
                Gästedaten, Mitarbeiter und Einstellungen werden unwiderruflich entfernt.
                Du wirst danach automatisch abgemeldet.
            </p>

            <div id="tenantDeleteForm" class="hidden">
                <form method="POST" action="{{ route('admin.account.tenant.destroy') }}">
                    @csrf @method('DELETE')
                    <label class="mb-1 block text-sm font-semibold text-red-700">
                        Tippe den Betriebsnamen zur Bestätigung:
                        <code class="rounded bg-red-200 px-1 font-mono">{{ $tenant->name }}</code>
                    </label>
                    <input type="text" name="confirm" required autocomplete="off"
                           class="mb-3 w-full rounded-xl border-2 border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:outline-none"
                           placeholder="{{ $tenant->name }}">
                    @error('confirm_tenant')
                        <p class="mb-2 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                    <div class="flex gap-3">
                        <button type="submit"
                                class="rounded-xl bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700">
                            Betrieb jetzt löschen
                        </button>
                        <button type="button"
                                onclick="this.closest('#tenantDeleteForm').classList.add('hidden'); document.getElementById('tenantDeleteBtn').classList.remove('hidden')"
                                class="rounded-xl bg-stone-100 px-5 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
            <button id="tenantDeleteBtn"
                    onclick="this.classList.add('hidden'); document.getElementById('tenantDeleteForm').classList.remove('hidden')"
                    class="rounded-xl border-2 border-red-400 px-5 py-2 text-sm font-bold text-red-600 hover:bg-red-100">
                Betrieb löschen …
            </button>
        </div>
    @endif

    {{-- Konto löschen --}}
    <div class="rounded-2xl border-2 border-red-200 bg-red-50 p-5">
        <h2 class="mb-1 font-bold text-red-700">Konto löschen</h2>
        <p class="mb-4 text-sm text-red-600">
            Das Löschen deines Kontos ist <strong>unwiderruflich</strong>. Alle deine Zugangsdaten werden entfernt.
            Buchungen und andere Betriebsdaten bleiben aus DSGVO-Gründen anonymisiert erhalten.
        </p>

        @if($isLastOwner)
            <div class="rounded-xl bg-red-100 px-4 py-3 text-sm text-red-700">
                Du bist der einzige Inhaber dieses Betriebs. Lösche den Betrieb zuerst (oben)
                oder übertrage die Inhaberrolle an ein anderes Teammitglied.
            </div>
        @else
            <div id="accountDeleteForm" class="hidden">
                <form method="POST" action="{{ route('admin.account.destroy') }}">
                    @csrf @method('DELETE')
                    <label class="mb-1 block text-sm font-semibold text-red-700">
                        Tippe <code class="rounded bg-red-200 px-1 font-mono">LÖSCHEN</code> zur Bestätigung:
                    </label>
                    <input type="text" name="confirm" required autocomplete="off"
                           class="mb-3 w-full rounded-xl border-2 border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:outline-none"
                           placeholder="LÖSCHEN">
                    @error('confirm')
                        <p class="mb-2 text-xs text-red-700">{{ $message }}</p>
                    @enderror
                    <div class="flex gap-3">
                        <button type="submit"
                                class="rounded-xl bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700">
                            Konto jetzt löschen
                        </button>
                        <button type="button"
                                onclick="this.closest('#accountDeleteForm').classList.add('hidden'); document.getElementById('accountDeleteBtn').classList.remove('hidden')"
                                class="rounded-xl bg-stone-100 px-5 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200">
                            Abbrechen
                        </button>
                    </div>
                </form>
            </div>
            <button id="accountDeleteBtn"
                    onclick="this.classList.add('hidden'); document.getElementById('accountDeleteForm').classList.remove('hidden')"
                    class="rounded-xl border-2 border-red-400 px-5 py-2 text-sm font-bold text-red-600 hover:bg-red-100">
                Konto löschen …
            </button>
        @endif
    </div>

</div>
@endsection

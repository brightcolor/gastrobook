<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Testzeitraum abgelaufen – Swayy</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">

<div class="flex min-h-screen items-start justify-center px-4 py-20">
    <div class="w-full max-w-2xl">

        {{-- Logo --}}
        <div class="mb-10 text-center">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5 text-2xl font-semibold"
               style="font-family:'Fraunces Variable',Georgia,serif">
                <img src="/logo-mark.png" alt="" class="h-9 w-9" style="border-radius:0.85rem">
                Swayy
            </a>
        </div>

        @if(session('success'))
            {{-- Status: Formular abgeschickt, warte auf E-Mail-Bestätigung --}}
            <div class="card p-10 text-center">
                <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-50">
                    <svg class="h-8 w-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-stone-900">Bestätigungslink gesendet</h1>
                <p class="mx-auto mt-4 max-w-md leading-relaxed text-stone-500">
                    {{ session('success') }}
                </p>
                <p class="mt-4 text-sm text-stone-400">Der Link ist 72 Stunden gültig.</p>
            </div>

        @else
            {{-- Standard: Trial abgelaufen, Formular anzeigen --}}
            <div class="card">
                <div class="border-b border-stone-100 px-8 py-6">
                    <div class="flex items-start gap-4">
                        <div class="mt-0.5 flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-50">
                            <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-lg font-bold text-stone-900">Ihr Testzeitraum ist abgelaufen</h1>
                            <p class="mt-1 text-sm leading-relaxed text-stone-500">
                                Um Swayy weiter zu nutzen, füllen Sie bitte das Formular aus.
                                Wir klären die Details direkt mit Ihnen und schalten Ihren Account anschließend frei.
                            </p>
                        </div>
                    </div>
                </div>

                <form action="{{ route('admin.trial.request') }}" method="POST" class="divide-y divide-stone-100">
                    @csrf

                    {{-- Kontakt --}}
                    <fieldset class="px-8 py-6">
                        <legend class="mb-4 text-xs font-semibold uppercase tracking-widest text-stone-400">Kontakt</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="contact_name">Ansprechpartner *</label>
                                <input id="contact_name" name="contact_name" type="text" required maxlength="120"
                                       value="{{ old('contact_name', auth()->user()->name) }}"
                                       class="w-full @error('contact_name') border-red-400 @enderror">
                                @error('contact_name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="contact_email">E-Mail-Adresse *</label>
                                <input id="contact_email" name="contact_email" type="email" required maxlength="200"
                                       value="{{ old('contact_email', auth()->user()->email) }}"
                                       class="w-full @error('contact_email') border-red-400 @enderror">
                                @error('contact_email')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="phone">Telefon</label>
                                <input id="phone" name="phone" type="tel" maxlength="40"
                                       value="{{ old('phone') }}" class="w-full">
                            </div>
                        </div>
                    </fieldset>

                    {{-- Rechnungsanschrift --}}
                    <fieldset class="px-8 py-6">
                        <legend class="mb-4 text-xs font-semibold uppercase tracking-widest text-stone-400">Rechnungsanschrift</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="company_name">Firma / Betriebsname</label>
                                <input id="company_name" name="company_name" type="text" maxlength="150"
                                       value="{{ old('company_name', $tenant->name) }}" class="w-full">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="address_line1">Straße &amp; Hausnummer *</label>
                                <input id="address_line1" name="address_line1" type="text" required maxlength="200"
                                       value="{{ old('address_line1') }}"
                                       class="w-full @error('address_line1') border-red-400 @enderror">
                                @error('address_line1')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="address_line2">Adresszusatz</label>
                                <input id="address_line2" name="address_line2" type="text" maxlength="200"
                                       value="{{ old('address_line2') }}" class="w-full">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="postal_code">PLZ *</label>
                                <input id="postal_code" name="postal_code" type="text" required maxlength="20"
                                       value="{{ old('postal_code') }}"
                                       class="w-full @error('postal_code') border-red-400 @enderror">
                                @error('postal_code')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="city">Stadt *</label>
                                <input id="city" name="city" type="text" required maxlength="100"
                                       value="{{ old('city') }}"
                                       class="w-full @error('city') border-red-400 @enderror">
                                @error('city')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="country">Land *</label>
                                <select id="country" name="country" class="w-full">
                                    <option value="DE" @selected(old('country','DE')==='DE')>Deutschland</option>
                                    <option value="AT" @selected(old('country')==='AT')>Österreich</option>
                                    <option value="CH" @selected(old('country')==='CH')>Schweiz</option>
                                    <option value="LU" @selected(old('country')==='LU')>Luxemburg</option>
                                    <option value="NL" @selected(old('country')==='NL')>Niederlande</option>
                                    <option value="BE" @selected(old('country')==='BE')>Belgien</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-semibold text-stone-700" for="vat_id">USt-IdNr.</label>
                                <input id="vat_id" name="vat_id" type="text" maxlength="50"
                                       value="{{ old('vat_id') }}" placeholder="DE…" class="w-full">
                            </div>
                        </div>
                    </fieldset>

                    {{-- Tarif-Wunsch --}}
                    <fieldset class="px-8 py-6">
                        <legend class="mb-4 text-xs font-semibold uppercase tracking-widest text-stone-400">Gewünschter Tarif *</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach($plans as $plan)
                                <label class="relative flex cursor-pointer rounded-xl border p-4 transition hover:border-teal-300
                                              {{ old('plan_key') === $plan->key ? 'border-teal-500 bg-teal-50' : 'border-stone-200 bg-white' }}">
                                    <input type="radio" name="plan_key" value="{{ $plan->key }}" required
                                           class="sr-only"
                                           {{ old('plan_key') === $plan->key ? 'checked' : '' }}
                                           onchange="document.querySelectorAll('[data-plan-label]').forEach(el=>el.classList.remove('border-teal-500','bg-teal-50'));this.closest('label').classList.add('border-teal-500','bg-teal-50')">
                                    <div data-plan-label>
                                        <p class="font-bold text-stone-900">{{ $plan->name }}</p>
                                        <p class="mt-0.5 text-sm text-stone-500">
                                            @if($plan->key === 'enterprise')
                                                Auf Anfrage
                                            @else
                                                {{ number_format($plan->price_monthly_minor / 100, 2, ',', '.') }} € / Monat
                                            @endif
                                        </p>
                                        @if(isset($plan->limits['max_tables']))
                                            <p class="mt-1 text-xs text-stone-400">bis {{ $plan->limits['max_tables'] }} Tische</p>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('plan_key')<p class="mt-2 text-xs text-red-500">{{ $message }}</p>@enderror
                    </fieldset>

                    {{-- Nachricht --}}
                    <fieldset class="px-8 py-6">
                        <legend class="mb-4 text-xs font-semibold uppercase tracking-widest text-stone-400">Nachricht (optional)</legend>
                        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                                  placeholder="Fragen, besondere Anforderungen, geplantes Startdatum …"
                                  class="w-full">{{ old('notes') }}</textarea>
                    </fieldset>

                    <div class="flex items-center justify-between px-8 py-5">
                        <form action="{{ route('logout') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-sm text-stone-400 hover:text-stone-600 underline">Abmelden</button>
                        </form>
                        <button type="submit" class="btn-brand rounded-xl px-6 py-2.5 text-sm font-semibold text-white">
                            Anfrage absenden →
                        </button>
                    </div>
                </form>
            </div>
        @endif

    </div>
</div>

</body>
</html>

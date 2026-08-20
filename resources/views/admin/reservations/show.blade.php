@extends('layouts.admin')
@section('title', 'Reservierung ' . $reservation->code)
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <a href="{{ route('admin.reservations.index', ['date' => $reservation->reservation_date->toDateString()]) }}" class="text-sm text-stone-500 hover:underline">← Zurück zum Buch</a>
        <h1 class="text-2xl font-bold">{{ $reservation->guest_name_snapshot }} <span class="text-base font-normal text-stone-500">{{ $reservation->code }}</span></h1>
    </div>
    <x-status-badge :status="$reservation->status" />
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        {{-- Details --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                <div><div class="text-stone-500">Datum</div><div class="font-bold">{{ $reservation->localStart()->translatedFormat('D, d.m.Y') }}</div></div>
                <div><div class="text-stone-500">Uhrzeit</div><div class="font-bold">{{ $reservation->localStart()->format('H:i') }}–{{ $reservation->localEnd()->format('H:i') }}</div></div>
                <div><div class="text-stone-500">Personen</div><div class="font-bold">{{ $reservation->party_size }}</div></div>
                <div><div class="text-stone-500">Quelle</div><div class="font-bold">{{ __('reservations.source.' . $reservation->source) }}</div></div>
                <div><div class="text-stone-500">Tische</div><div class="font-bold">{{ $reservation->tables->map(fn ($t) => $t->name . ' (' . $t->room?->name . ')')->implode(', ') ?: '–' }}
                    @if($reservation->table_chosen_by_guest)<span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Wunschtisch vom Gast</span>@endif
                </div></div>
                <div><div class="text-stone-500">E-Mail</div><div class="font-bold">{{ $reservation->guest_email_snapshot ?? '–' }}</div></div>
                <div><div class="text-stone-500">Telefon</div><div class="font-bold">{{ $reservation->guest_phone_snapshot ?? '–' }}</div></div>
                <div><div class="text-stone-500">Anlass</div><div class="font-bold">{{ $reservation->occasion ?? '–' }}</div></div>
                <div><div class="text-stone-500">Gebucht am</div><div class="font-bold">{{ $reservation->localCreatedAt()?->translatedFormat('D, d.m.Y H:i') ?? '–' }}</div></div>
            </div>
            @if($reservation->guest_note)
                <div class="mt-4 rounded-xl bg-stone-50 p-3 text-sm"><strong>Gastnotiz:</strong> {{ $reservation->guest_note }}</div>
            @endif
            @if($reservation->allergy_note)
                <div class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><strong>⚠️ Allergien:</strong> {{ $reservation->allergy_note }}</div>
            @endif
            @if($reservation->internal_note)
                <div class="mt-2 rounded-xl bg-blue-50 p-3 text-sm text-blue-900"><strong>Intern:</strong> {{ $reservation->internal_note }}</div>
            @endif
            @if($reservation->payment_status !== 'not_required')
                @php
                    $payLabels = [
                        'required' => 'Anzahlung erforderlich',
                        'pending' => 'Zahlung ausstehend',
                        'paid' => 'Anzahlung bezahlt',
                        'refunded' => 'Anzahlung erstattet',
                        'forfeited' => 'Anzahlung einbehalten (No-Show)',
                        'failed' => 'Zahlung fehlgeschlagen',
                        'expired' => 'Zahlungsfrist abgelaufen',
                    ];
                    $isForfeited = $reservation->payment_status === 'forfeited';
                @endphp
                <div class="mt-2 rounded-xl p-3 text-sm {{ $isForfeited ? 'bg-red-50 text-red-900' : 'bg-orange-50 text-orange-900' }}">
                    <strong>Zahlung:</strong> {{ $payLabels[$reservation->payment_status] ?? $reservation->payment_status }}
                    @if($reservation->payment_amount_minor) – {{ number_format($reservation->payment_amount_minor / 100, 2, ',', '.') }} {{ $reservation->currency }} @endif
                    @if($isForfeited)
                        <span class="mt-1 block text-xs">Der Betrag verbleibt beim Betrieb und wird nicht erstattet.</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- Quick actions --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-3 font-bold">Aktionen</h2>
            <div class="flex flex-wrap gap-2">
                @foreach(\App\Enums\ReservationStatus::transitions()[$reservation->status->value] ?? [] as $target)
                    @php
                        $labels = [
                            'confirmed' => ['Bestätigen', 'bg-emerald-600 text-white'],
                            'seated' => ['Gast ist da', 'bg-emerald-600 text-white'],
                            'partially_arrived' => ['Teilweise da', 'bg-blue-100 text-blue-800'],
                            'completed' => ['✓ Auschecken', 'bg-emerald-600 text-white shadow-sm'],
                            'no_show' => ['No-Show', 'bg-red-100 text-red-700'],
                            'rejected' => ['Ablehnen', 'bg-red-100 text-red-700'],
                            'cancelled_by_restaurant' => ['Stornieren (Restaurant)', 'bg-red-100 text-red-700'],
                            'cancelled_by_guest' => ['Stornieren (Gast)', 'bg-red-50 text-red-600'],
                            'waitlisted' => ['Auf Warteliste', 'bg-purple-100 text-purple-800'],
                        ];
                        $confirm = [
                            'completed' => ['Gäste auschecken?', 'Der Tisch wird freigegeben und die Reservierung abgeschlossen.', '✓ Auschecken', ''],
                            'no_show' => ['Als No-Show markieren?', 'Der Gast wird als nicht erschienen markiert.', 'No-Show', 'danger'],
                            'rejected' => ['Reservierung ablehnen?', 'Die Reservierung wird abgelehnt.', 'Ablehnen', 'danger'],
                            'cancelled_by_restaurant' => ['Stornieren?', 'Die Reservierung wird storniert.', 'Stornieren', 'danger'],
                            'cancelled_by_guest' => ['Stornieren?', 'Die Reservierung wird storniert.', 'Stornieren', 'danger'],
                        ];
                    @endphp
                    @if(isset($labels[$target]))
                        <form method="POST" action="{{ route('admin.reservations.transition', $reservation) }}"
                            @isset($confirm[$target])
                                data-confirm="{{ $confirm[$target][1] }}" data-confirm-title="{{ $confirm[$target][0] }}"
                                data-confirm-ok="{{ $confirm[$target][2] }}" data-confirm-style="{{ $confirm[$target][3] }}"
                            @endisset>
                            @csrf
                            <input type="hidden" name="status" value="{{ $target }}">
                            <button class="rounded-xl px-4 py-2.5 text-sm font-semibold {{ $labels[$target][1] }}">{{ $labels[$target][0] }}</button>
                        </form>
                    @endif
                @endforeach
            </div>

            {{-- Table change --}}
            <form method="POST" action="{{ route('admin.reservations.tables', $reservation) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-stone-100 pt-4">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-semibold text-stone-500">Tisch wechseln</label>
                    <select name="table_ids[]" multiple size="4" class="rounded-lg border-stone-200 text-sm">
                        @foreach($location->rooms()->with('tables')->get() as $room)
                            <optgroup label="{{ $room->name }}">
                                @foreach($room->tables as $table)
                                    <option value="{{ $table->id }}" @selected($reservation->tables->contains('id', $table->id))>
                                        {{ $table->name }} ({{ $table->min_capacity }}–{{ $table->max_capacity }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="force" value="1"> Überbuchung erlauben</label>
                <button class="rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white">Übernehmen</button>
            </form>
        </div>

        {{-- History --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-3 font-bold">Verlauf<span class="tip" tabindex="0" data-tip="Lückenlose Historie dieser Reservierung: wer wann welchen Schritt gemacht hat. Hilft, wenn im Nachhinein unklar ist, wer etwas geändert oder storniert hat.">?</span></h2>
            <div class="space-y-2 text-sm">
                @foreach($reservation->statusHistories->sortByDesc('created_at') as $h)
                    <div class="flex items-center gap-3 border-l-2 border-stone-200 pl-3">
                        <span class="text-stone-400">{{ $h->created_at->copy()->setTimezone($location?->timezone ?? config('app.timezone'))->format('d.m. H:i') }}</span>
                        <span>{{ $h->from_status ? __('reservations.status.' . $h->from_status) . ' → ' : '' }}<strong>{{ __('reservations.status.' . $h->to_status) }}</strong></span>
                        <span class="text-stone-500">{{ $h->user?->name ?? ($h->actor === 'guest' ? 'Gast' : 'System') }}</span>
                        @if($h->reason)<span class="text-stone-400">({{ $h->reason }})</span>@endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Guest profile --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-3 font-bold">Gastprofil</h2>
            @if($reservation->guest)
                <a href="{{ route('admin.guests.show', $reservation->guest) }}" class="font-semibold text-brand hover:underline">{{ $reservation->guest->fullName() }} @if($reservation->guest->is_vip)⭐@endif</a>
                @if($reservation->guest->isRegular())<span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Stammgast</span>@endif
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-500">Besuche</dt><dd class="font-semibold">{{ $reservation->guest->visit_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">No-Shows</dt><dd class="font-semibold">{{ $reservation->guest->no_show_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">Stornos</dt><dd class="font-semibold">{{ $reservation->guest->cancellation_count }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">No-Show-Risiko</dt><dd class="font-semibold">{{ $reservation->no_show_risk }} %</dd></div>
                </dl>
                @if($reservation->guest->allergies)
                    <div class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">⚠️ {{ $reservation->guest->allergies }}</div>
                @endif
                @if($reservation->guest->tags->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach($reservation->guest->tags as $tag)
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background:{{ $tag->color }}22;color:{{ $tag->color }}">{{ $tag->name }}</span>
                        @endforeach
                    </div>
                @endif
            @else
                <p class="text-sm text-stone-500">Kein Gastprofil verknüpft (keine Kontaktdaten).</p>
            @endif
        </div>

        {{-- Tags --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-3 font-bold">Tags</h2>
            <div id="tagPills" class="flex flex-wrap gap-1.5 text-xs">
                @foreach($reservation->tags as $tag)
                    <span class="tag-pill flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold"
                          style="background:{{ $tag->color }}22;color:{{ $tag->color }}"
                          data-id="{{ $tag->id }}">
                        {{ $tag->name }}
                        <button class="tag-remove ml-0.5 opacity-60 hover:opacity-100" data-id="{{ $tag->id }}">✕</button>
                    </span>
                @endforeach
                <button id="tagAddBtn"
                        class="rounded-full border border-dashed border-stone-300 px-2.5 py-1 text-stone-400 hover:border-stone-400 hover:text-stone-600">
                    + Tag
                </button>
            </div>
            <div id="tagPicker" class="mt-3 hidden">
                <div id="tagList" class="mb-2 flex flex-wrap gap-1.5 text-xs"></div>
                <div class="flex gap-2">
                    <input id="tagNameInput" type="text" maxlength="40" placeholder="Neuer Tag-Name"
                           class="flex-1 rounded-lg border-stone-200 text-xs">
                    <input id="tagColorInput" type="color" value="#6b7280"
                           class="h-8 w-8 cursor-pointer rounded-lg border-stone-200">
                    <button id="tagCreateBtn"
                            class="rounded-lg bg-stone-900 px-3 py-1.5 text-xs font-semibold text-white">Anlegen</button>
                </div>
            </div>
        </div>

        {{-- Salon: Mitarbeiterin zuweisen --}}
        @php($istSalon = $reservation->location?->tenant?->isSalon())
        @if($istSalon && $canEditReservation)
            @php($personen = \App\Models\StaffMember::where('location_id', $reservation->location_id)->where('is_active', true)->orderBy('name')->get())
            @if($personen->isNotEmpty())
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
                    <h2 class="mb-1 font-bold">Mitarbeiter<span class="tip" tabindex="0" data-tip="Wer diesen Termin übernimmt. Telefonisch angelegte Termine haben oft noch niemanden – ohne Zuordnung stehen sie im Terminplan nur in der Restliste.">?</span></h2>
                    <p class="mb-3 text-xs text-stone-500">
                        Aktuell: <strong>{{ $reservation->staffMember?->name ?? 'niemand zugewiesen' }}</strong>
                    </p>
                    <form method="POST" action="{{ route('admin.reservations.staff', $reservation) }}" class="flex flex-wrap items-end gap-2 text-sm">
                        @csrf
                        <select name="staff_member_id" required class="rounded-lg border-stone-200 text-sm">
                            @foreach($personen as $person)
                                <option value="{{ $person->id }}" @selected($reservation->staff_member_id === $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                        <button class="rounded-lg bg-stone-900 px-3 py-2 text-xs font-semibold text-white">Zuweisen</button>
                    </form>
                    @error('staff_member_id')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
        @endif

        {{-- Attachments --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-1 font-bold">Anhänge<span class="tip" tabindex="0" data-tip="Dateien zu dieser Buchung – etwa die abgestimmte Menükarte, eine Tischskizze oder ein unterschriebener Vertrag fürs Event. Nur fürs Team sichtbar, Gäste bekommen sie nicht zu sehen.">?</span></h2>
            <p class="mb-3 text-xs text-stone-500">PDF oder Bild, max. 8 MB. Sichtbar nur für euer Team.</p>
            <div class="space-y-2 text-sm">
                @forelse($reservation->attachments->sortByDesc('created_at') as $attachment)
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-stone-50 p-2.5">
                        <div class="min-w-0">
                            <a href="{{ route('admin.reservations.attachments.download', [$reservation, $attachment]) }}"
                               class="block truncate font-semibold text-teal-700 hover:underline">{{ $attachment->original_name }}</a>
                            <div class="text-xs text-stone-400">
                                {{ $attachment->humanSize() }} · {{ $attachment->created_at->copy()->setTimezone($location?->timezone ?? config('app.timezone'))->format('d.m.Y') }}
                                @if($attachment->uploader) · {{ $attachment->uploader->name }} @endif
                            </div>
                        </div>
                        @if($canEditReservation)
                            <form method="POST" action="{{ route('admin.reservations.attachments.destroy', [$reservation, $attachment]) }}"
                                  onsubmit="return confirm('Datei löschen?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:text-red-700">Löschen</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-stone-400">Noch keine Dateien.</p>
                @endforelse
            </div>
            @if($canEditReservation)
                <form method="POST" action="{{ route('admin.reservations.attachments.store', $reservation) }}"
                      enctype="multipart/form-data" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    @csrf
                    <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="text-xs">
                    <button class="rounded-lg bg-stone-900 px-3 py-1.5 text-xs font-bold text-white">Hochladen</button>
                </form>
                @error('file')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            @endif
        </div>

        {{-- Internal notes --}}
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <h2 class="mb-1 font-bold">Notizen<span class="tip" tabindex="0" data-tip="Kurze Vermerke zu genau dieser Buchung – „ruft nochmal an wegen Kinderstuhl“, „kommt später“. Nur fürs Team, der Gast sieht sie nie. Dauerhafte Vorlieben gehören ins Gastprofil.">?</span></h2>
            <p class="mb-3 text-xs text-stone-500">Nur fürs Team, der Gast sieht sie nie.</p>
            @if($canEditReservation)
                <form method="POST" action="{{ route('admin.reservations.notes', $reservation) }}" class="mb-3 space-y-2">
                    @csrf
                    <textarea name="body" rows="2" required maxlength="2000" placeholder="Neue Notiz…" class="w-full rounded-lg border-stone-200 text-sm"></textarea>
                    <button class="rounded-lg bg-stone-900 px-3 py-1.5 text-xs font-bold text-white">Speichern</button>
                </form>
                @error('body')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
            @endif
            <div class="space-y-2 text-sm">
                @forelse($reservation->notes->sortByDesc('created_at') as $note)
                    <div class="rounded-lg bg-stone-50 p-2.5">
                        {{ $note->body }}
                        <div class="mt-1 text-xs text-stone-400">{{ $note->user?->name }} · {{ $note->created_at->copy()->setTimezone($location?->timezone ?? config('app.timezone'))->format('d.m. H:i') }}</div>
                    </div>
                @empty
                    <p class="text-xs text-stone-400">Noch keine Notizen.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? @json(csrf_token());
    const syncUrl = @json(route('admin.reservations.tags', $reservation));
    const indexUrl = @json(route('admin.tags.index'));
    const storeUrl = @json(route('admin.tags.store'));

    let activeTags = @json($reservation->tags->pluck('id')->values());
    let allTags = [];

    async function loadTags() {
        const res = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
        if (res.ok) allTags = await res.json();
        renderPicker();
    }

    async function sync() {
        await fetch(syncUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ tag_ids: activeTags }),
        });
    }

    function renderPills() {
        const container = document.getElementById('tagPills');
        const btn = document.getElementById('tagAddBtn');
        // Remove old pills (keep the add-button)
        container.querySelectorAll('.tag-pill').forEach(el => el.remove());
        activeTags.forEach(id => {
            const tag = allTags.find(t => t.id === id);
            if (!tag) return;
            const pill = document.createElement('span');
            pill.className = 'tag-pill flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold';
            pill.style.background = tag.color + '22';
            pill.style.color = tag.color;
            pill.dataset.id = tag.id;
            pill.innerHTML = `${tag.name} <button class="tag-remove ml-0.5 opacity-60 hover:opacity-100" data-id="${tag.id}">✕</button>`;
            btn.before(pill);
        });
        container.querySelectorAll('.tag-remove').forEach(b => b.addEventListener('click', () => {
            activeTags = activeTags.filter(id => id != b.dataset.id);
            sync();
            renderPills();
        }));
    }

    function renderPicker() {
        const list = document.getElementById('tagList');
        list.innerHTML = allTags.map(tag => {
            const active = activeTags.includes(tag.id);
            return `<button class="tag-pick rounded-full px-2.5 py-1 font-semibold ring-1 transition"
                            style="background:${active ? tag.color + '22' : 'transparent'};color:${tag.color};ring-color:${tag.color}"
                            data-id="${tag.id}">${tag.name}</button>`;
        }).join('');
        list.querySelectorAll('.tag-pick').forEach(b => b.addEventListener('click', () => {
            const id = +b.dataset.id;
            if (activeTags.includes(id)) {
                activeTags = activeTags.filter(x => x !== id);
            } else {
                activeTags.push(id);
            }
            sync();
            renderPills();
            renderPicker();
        }));
    }

    document.getElementById('tagAddBtn').addEventListener('click', () => {
        document.getElementById('tagPicker').classList.toggle('hidden');
        if (!allTags.length) loadTags();
    });

    document.getElementById('tagCreateBtn').addEventListener('click', async () => {
        const name = document.getElementById('tagNameInput').value.trim();
        const color = document.getElementById('tagColorInput').value;
        if (!name) return;
        const res = await fetch(storeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ name, color }),
        });
        if (!res.ok) return;
        const tag = await res.json();
        if (!allTags.find(t => t.id === tag.id)) allTags.push(tag);
        if (!activeTags.includes(tag.id)) activeTags.push(tag.id);
        document.getElementById('tagNameInput').value = '';
        sync();
        renderPills();
        renderPicker();
    });

    // Initialize pills from server-rendered state
    loadTags().then(renderPills);
})();
</script>
@endsection

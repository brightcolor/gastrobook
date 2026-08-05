@extends('layouts.admin')
@section('title', 'Webhooks')
@section('content')
<h1 class="mb-1 text-2xl font-bold">Webhooks<span class="tip" tabindex="0" data-tip="Ein Webhook meldet Ereignisse – etwa eine neue Reservierung – automatisch an ein anderes Programm, z. B. eure Website, ein Kassensystem oder einen Automatisierungsdienst. Nur anlegen, wenn ein Dienstleister ausdrücklich danach fragt.">?</span></h1>
<p class="mb-5 text-sm text-stone-500">Swayy schickt bei jedem gewählten Ereignis eine signierte Nachricht an deine Adresse.</p>

@if(session('new_secret'))
    <div class="mb-4 rounded-2xl bg-emerald-50 p-4 text-sm">
        <p class="font-bold text-emerald-900">Secret – jetzt kopieren, es wird nur einmal angezeigt:</p>
        <code class="mt-2 block break-all rounded-lg bg-white p-3">{{ session('new_secret') }}</code>
        <p class="mt-2 text-xs text-emerald-900">Damit prüft die Gegenstelle den Header <code>X-Gastrobook-Signature: sha256=&lt;HMAC-SHA256(Body, Secret)&gt;</code>.</p>
    </div>
@endif

@unless($webhooksEnabled)
    <div class="mb-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Webhooks sind im aktuellen Tarif nicht enthalten – bestehende Endpunkte bleiben stumm.</div>
@endunless

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="font-bold">Endpunkte</h2>
        <div class="mt-3 space-y-3 text-sm">
            @forelse($endpoints as $endpoint)
                <div class="rounded-xl bg-stone-50 p-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <code class="block break-all font-semibold">{{ $endpoint->url }}</code>
                            <div class="mt-1 text-xs text-stone-500">
                                {{ in_array('*', $endpoint->events ?? [], true) ? 'alle Ereignisse' : implode(', ', $endpoint->events ?? []) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-400">
                                {{ $endpoint->deliveries_count }} Zustellungen
                                @if($endpoint->failure_count > 0) · {{ $endpoint->failure_count }} Fehler in Folge @endif
                                @if($endpoint->disabled_at) · abgeschaltet am {{ $endpoint->disabled_at->format('d.m.Y H:i') }} @endif
                            </div>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $endpoint->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' }}">
                            {{ $endpoint->is_active ? 'aktiv' : 'pausiert' }}
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                        <form method="POST" action="{{ route('admin.webhooks.toggle', $endpoint) }}">
                            @csrf
                            <button class="font-semibold text-teal-700 hover:underline">{{ $endpoint->is_active ? 'Pausieren' : 'Aktivieren' }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.webhooks.ping', $endpoint) }}">
                            @csrf
                            <button class="font-semibold text-teal-700 hover:underline">Testereignis senden</button>
                        </form>
                        <form method="POST" action="{{ route('admin.webhooks.rotate', $endpoint) }}"
                              onsubmit="return confirm('Neues Secret erzeugen? Die Gegenstelle muss danach umgestellt werden.')">
                            @csrf
                            <button class="font-semibold text-teal-700 hover:underline">Secret neu erzeugen</button>
                        </form>
                        <form method="POST" action="{{ route('admin.webhooks.destroy', $endpoint) }}"
                              onsubmit="return confirm('Webhook löschen?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500 hover:underline">Löschen</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="py-3 text-stone-500">Noch kein Webhook eingerichtet.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="font-bold">Webhook anlegen</h2>
        <form method="POST" action="{{ route('admin.webhooks.store') }}" class="mt-3 space-y-3 text-sm">
            @csrf
            <label class="block">Adresse (https)
                <input type="url" name="url" required value="{{ old('url') }}" placeholder="https://example.com/swayy-webhook"
                       class="mt-1 w-full rounded-lg border-stone-200">
            </label>
            @error('url')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <div>
                <p class="mb-1 font-semibold">Ereignisse<span class="tip" tabindex="0" data-tip="Wähle aus, worüber die Gegenstelle informiert werden soll. „Alle Ereignisse“ nimmt automatisch auch künftige mit.">?</span></p>
                <label class="mb-1 flex items-center gap-1.5"><input type="checkbox" name="events[]" value="*"> <strong>Alle Ereignisse</strong></label>
                <div class="grid grid-cols-2 gap-1.5">
                    @foreach($events as $event)
                        <label class="flex items-center gap-1.5"><input type="checkbox" name="events[]" value="{{ $event }}"> <code class="text-xs">{{ $event }}</code></label>
                    @endforeach
                </div>
                @error('events')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white" @unless($webhooksEnabled) disabled style="opacity:.5" @endunless>Webhook anlegen</button>
        </form>
        <div class="mt-4 rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
            <p class="font-semibold">Gut zu wissen</p>
            <ul class="mt-1 list-disc space-y-1 pl-4">
                <li>Nur öffentlich erreichbare https-Adressen – interne Adressen werden abgelehnt.</li>
                <li>Fehlversuche werden wiederholt (1 Min. bis 2 Std., 5 Versuche).</li>
                <li>Nach 20 Fehlern in Folge schaltet sich der Endpunkt selbst ab; hier lässt er sich wieder aktivieren.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100 lg:col-span-2">
        <h2 class="font-bold">Zustellprotokoll<span class="tip" tabindex="0" data-tip="Die letzten 25 Versuche. „erfolgreich“ heißt: die Gegenstelle hat die Nachricht angenommen. Bei Fehlern steht hier der Statuscode, den sie zurückgegeben hat.">?</span></h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-stone-400">
                    <tr><th class="py-2">Zeitpunkt</th><th>Ereignis</th><th>Ziel</th><th>Versuch</th><th>Status</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-50">
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td class="py-2 whitespace-nowrap">{{ $delivery->created_at->format('d.m.Y H:i') }}</td>
                            <td><code class="text-xs">{{ $delivery->event }}</code></td>
                            <td class="max-w-xs truncate text-xs text-stone-500">{{ $delivery->endpoint?->url }}</td>
                            <td class="text-xs">{{ $delivery->attempt }}</td>
                            <td class="text-xs">
                                @if($delivery->status === 'success')
                                    <span class="font-semibold text-emerald-700">erfolgreich</span>
                                @elseif($delivery->status === 'failed')
                                    <span class="font-semibold text-red-600">fehlgeschlagen</span>
                                @else
                                    <span class="text-stone-500">unterwegs</span>
                                @endif
                                @if($delivery->response_code) · HTTP {{ $delivery->response_code }} @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-stone-500">Noch nichts zugestellt.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

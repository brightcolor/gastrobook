@use('App\Support\CapacityModes')
{{-- Formular einer Ausnahme in der Seitenleiste (resources/js/admin/exceptions.js, reveal.js, rows.js).
     $assignsTables sagt, ob Swayy am Standort Tische vergibt (Zählweise $capacityMode); sonst fehlen die Karte
     „Räume oder Tische sperren“ und die Wahl des Raums bei „Weniger Gäste“, außer ein Eintrag trägt sie schon.
     $prefix ist die Kennung der Seitenleiste ("ausnahme-neu", "ausnahme-g-…") und macht alle IDs eindeutig,
     $action und $method gehen an Anlegen oder Ändern, $key nennt beim Ändern den Eintrag (die Vorschau lässt dessen
     eigene Zeilen aus), $values trägt die Felder (Eintrag['form'] beim Ändern, Vorbelegung beim Anlegen).
     $rooms nennt die Räume und Tische, die das Formular anbietet: die aktiven und die, auf die $values verweist;
     inaktive tragen "(inaktiv)" und bleiben angehakt oder gewählt, damit Speichern keinen Verweis verliert.
     $preset ist beim Anlegen die Nummer des Tisches aus ?tisch=…; die vorbelegten Kästchen tragen data-preset
     (exceptions.js nimmt die Vorbelegung beim Schließen zurück).
     Die Abschnitte der Wirkungen bleiben im Formular und werden mitgesendet; der Server wertet nur die gewählten aus.
     novalidate: Der Server prüft und nennt jeden Fehler mit Ursache und nächstem Schritt. Die Prüfung des Browsers stoppte
     den Versand schon bei einem leeren Pflichtfeld in einem verborgenen Abschnitt, dessen Grund niemand sieht. --}}
@php
    $has = fn (string $effect): bool => in_array($effect, $values['effects'], true);
    $id = fn (string $name): string => $prefix.'-'.$name;
    $effects = [
        'closed' => ['Geschlossen', 'Niemand kann online buchen.'],
        'hours' => ['Andere Öffnungszeiten', 'Zum Beispiel kürzer an Heiligabend.'],
    ];
    // Räume und Tische wirken nur, wo Swayy Tische vergibt ($assignsTables). Ein Eintrag, der sie schon trägt, zeigt Karte und
    // Abschnitt weiter, damit Speichern nichts stumm entfernt; der Server verlangt dann, sie abzuwählen oder die Zählweise zu ändern.
    $showLock = $assignsTables || $has('lock');
    $showRoomChoice = $assignsTables || $values['limit_room_id'] !== null;
    $modeLabel = CapacityModes::label($capacityMode);
    if (! $salon) {
        $effects['limit'] = ['Weniger Gäste', 'Höchstens so viele Gäste gleichzeitig.'];
        if ($showLock) {
            $effects['lock'] = ['Räume oder Tische sperren', 'Etwa für eine geschlossene Gesellschaft.'];
        }
    }
    $windows = $values['windows'] === [] ? [['opens_at' => '', 'closes_at' => '']] : $values['windows'];
    $card = 'flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)';
@endphp
<form id="form-{{ $prefix }}" method="POST" action="{{ $action }}" novalidate data-ajax data-reload-after-save @if($method === 'POST') data-reset-after-save @endif
      data-preview-url="{{ route('admin.settings.exceptions.preview') }}" data-preview-delay="{{ $previewDelay }}" class="space-y-6">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif
    @if($key !== null)
        <input type="hidden" name="key" value="{{ $key }}">
    @endif

    <div class="flex flex-wrap items-start gap-4">
        <x-field name="from" :id="$id('from')" label="Von" type="date" required :value="$values['from']" class="min-w-40 flex-1" />
        <x-field name="until" :id="$id('until')" label="Bis" type="date" :value="$values['until']" class="min-w-40 flex-1"
                 help="Leer lassen für einen einzelnen Tag." />
    </div>
    <x-field name="label" :id="$id('label')" label="Bezeichnung" placeholder="z. B. Betriebsferien" autocomplete="off"
             :maxlength="$limits['label_max']" :value="$values['label']" />

    <fieldset id="{{ $id('effects') }}" data-error-group class="space-y-3">
        <legend class="mb-2 text-sm font-semibold text-stone-800">Was ist anders?</legend>
        @foreach($effects as $value => [$title, $summary])
            <label class="{{ $card }}">
                <input type="checkbox" id="{{ $id('effects-'.$value) }}" name="effects[]" value="{{ $value }}" @checked($has($value)) @if($preset !== null && $value === 'lock') data-preset="tisch" @endif class="mt-1 h-6 w-6 shrink-0">
                <span class="min-w-0 [overflow-wrap:anywhere]">
                    <span class="block font-semibold">{{ $title }}</span>
                    <span class="block text-[0.8125rem] text-stone-600">{{ $summary }}</span>
                </span>
            </label>
        @endforeach
    </fieldset>

    <div data-show-if="effects[]:hours" @unless($has('hours')) hidden @endunless class="space-y-3 border-t border-stone-200 pt-5">
        <h3 class="text-sm font-semibold text-stone-900">Andere Öffnungszeiten</h3>
        <p class="text-[0.8125rem] text-stone-600">An diesen Tagen gelten nur diese Zeiten. Mehrere Fenster, etwa Mittag und Abend, ergeben eine Pause dazwischen.</p>
        <div data-rows data-rows-max="{{ $limits['windows_max'] }}" class="space-y-3">
            <div data-rows-list class="space-y-3">
                @foreach($windows as $index => $window)
                    @include('admin.settings.partials.exception-window', ['prefix' => $prefix, 'key' => $index, 'window' => $window])
                @endforeach
            </div>
            <template data-rows-template>
                @include('admin.settings.partials.exception-window', ['prefix' => $prefix, 'key' => '__INDEX__', 'window' => []])
            </template>
            <button type="button" data-rows-add class="btn btn-secondary">Zeitfenster hinzufügen</button>
        </div>
    </div>

    @unless($salon)
        <div data-show-if="effects[]:limit" @unless($has('limit')) hidden @endunless class="space-y-4 border-t border-stone-200 pt-5">
            <h3 class="text-sm font-semibold text-stone-900">Weniger Gäste</h3>
            <x-field name="limit_covers" :id="$id('limit_covers')" label="Höchstens gleichzeitig" type="number" unit="Gäste" min="1"
                     :max="$limits['covers_max']" inputmode="numeric" :value="$values['limit_covers']" class="max-w-56" />
            @if($showRoomChoice)
                <x-field name="limit_room_id" :id="$id('limit_room_id')" label="Gilt für"
                         :help="$assignsTables ? null : 'Im Modus „'.$modeLabel.'“ vergibt Swayy keine Räume. Zum Speichern bitte „Ganzer Betrieb“ wählen oder unter Kapazität & Dauer die Zählweise ändern.'">
                    <select id="{{ $id('limit_room_id') }}" name="limit_room_id" @unless($assignsTables) aria-describedby="{{ $id('limit_room_id') }}-help" @endunless class="w-full">
                        <option value="">Ganzer Betrieb</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}" @selected((int) $values['limit_room_id'] === (int) $room->id)>Nur: {{ $room->name }}{{ $room->is_active ? '' : ' (inaktiv)' }}</option>
                        @endforeach
                    </select>
                </x-field>
            @else
                <p class="text-[0.8125rem] text-stone-600">Im Modus „{{ $modeLabel }}“ gilt die Grenze für den ganzen Betrieb.</p>
            @endif
            <fieldset class="space-y-2">
                <legend class="text-sm font-semibold text-stone-800">Uhrzeit</legend>
                <div class="flex flex-wrap items-start gap-3">
                    <x-field name="limit_from" :id="$id('limit_from')" label="Beginn" type="time" :value="$values['limit_from']" class="w-32" />
                    <x-field name="limit_until" :id="$id('limit_until')" label="Ende" type="time" :value="$values['limit_until']" class="w-32" />
                </div>
                <p class="text-[0.8125rem] text-stone-500">Leer lassen für den ganzen Tag.</p>
            </fieldset>
        </div>

        @if($showLock)
        <div data-show-if="effects[]:lock" @unless($has('lock')) hidden @endunless class="space-y-4 border-t border-stone-200 pt-5">
            <h3 class="text-sm font-semibold text-stone-900">Räume oder Tische sperren</h3>
            @unless($assignsTables)
                <p class="text-[0.8125rem] text-stone-600">Im Modus „{{ $modeLabel }}“ vergibt Swayy keine Tische. Diese Sperre wirkt auf Buchungen in den Zählweisen {{ CapacityModes::assigningList() }}. Zum Speichern bitte „Räume oder Tische sperren“ abwählen oder unter Kapazität &amp; Dauer die Zählweise ändern.</p>
            @endunless
            @if($rooms->isEmpty())
                <p class="text-sm text-stone-600">An diesem Standort gibt es noch keine Räume. Lege unter <a href="{{ route('admin.settings.rooms.index') }}" class="font-semibold underline underline-offset-2">Räume &amp; Tische</a> einen Raum mit Tischen an.</p>
            @else
                <fieldset id="{{ $id('lock') }}" data-error-group class="space-y-4">
                    <legend class="mb-2 text-sm font-semibold text-stone-800">Was wird gesperrt?</legend>
                    @foreach($rooms as $room)
                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <input type="checkbox" id="{{ $id('lock_rooms-'.$room->id) }}" name="lock_rooms[]" value="{{ $room->id }}" @checked(in_array((int) $room->id, $values['lock_rooms'], true)) class="h-6 w-6 shrink-0">
                                <label for="{{ $id('lock_rooms-'.$room->id) }}" class="min-w-0 text-sm font-semibold text-stone-800 [overflow-wrap:anywhere]">Ganzer Raum „{{ $room->name }}“{{ $room->is_active ? '' : ' (inaktiv)' }}</label>
                            </div>
                            @foreach($room->tables as $table)
                                <div class="flex items-center gap-3 pl-9">
                                    <input type="checkbox" id="{{ $id('lock_tables-'.$table->id) }}" name="lock_tables[]" value="{{ $table->id }}" @checked(in_array((int) $table->id, $values['lock_tables'], true)) @if($preset === (int) $table->id) data-preset="tisch" @endif class="h-6 w-6 shrink-0">
                                    <label for="{{ $id('lock_tables-'.$table->id) }}" class="min-w-0 text-sm text-stone-800 [overflow-wrap:anywhere]">Tisch {{ $table->name }} · {{ $table->min_capacity }}–{{ $table->max_capacity }} Personen{{ $room->is_active && $table->is_active ? '' : ' (inaktiv)' }}</label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </fieldset>
                <fieldset class="space-y-2">
                    <legend class="text-sm font-semibold text-stone-800">Uhrzeit</legend>
                    <div class="flex flex-wrap items-start gap-3">
                        <x-field name="lock_from" :id="$id('lock_from')" label="Beginn" type="time" :value="$values['lock_from']" class="w-32" />
                        <x-field name="lock_until" :id="$id('lock_until')" label="Ende" type="time" :value="$values['lock_until']" class="w-32" />
                    </div>
                    <p class="text-[0.8125rem] text-stone-500">Leer lassen für den ganzen Tag.</p>
                </fieldset>
            @endif
        </div>
        @endif
    @endunless
</form>

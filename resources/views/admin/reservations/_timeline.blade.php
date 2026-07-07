{{-- Day timeline: tables (y) × opening hours (x), reservations as bars. --}}
@php($t = $timeline)
<div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    <div class="mb-3 flex items-center justify-between">
        <p class="text-sm font-semibold text-stone-700">{{ $t['day']->locale('de')->isoFormat('dddd, D. MMMM YYYY') }}</p>
        <p class="text-xs text-stone-400">{{ $t['dayStart']->format('H:i') }}–{{ $t['dayEnd']->format('H:i') }} Uhr · {{ $t['rooms']->sum(fn($r) => $r['tables']->count()) }} Tische</p>
    </div>

    @if($t['rooms']->sum(fn($r) => $r['tables']->count()) === 0)
        <p class="py-8 text-center text-sm text-stone-500">Keine Tische angelegt.</p>
    @else
    <div class="overflow-x-auto">
        <div class="min-w-[52rem]">
            {{-- Hour header --}}
            <div class="relative mb-1 ml-40 h-5 border-b border-stone-100">
                @foreach($t['hours'] as $h)
                    @php($pct = round($t['dayStart']->diffInMinutes($h) / $t['span'] * 100, 2))
                    <span class="absolute -translate-x-1/2 text-[10px] font-semibold text-stone-400" style="left:{{ $pct }}%">{{ $h->format('H') }}</span>
                @endforeach
            </div>

            @foreach($t['rooms'] as $room)
                <div class="mt-3 mb-1 ml-40 text-[10px] font-bold uppercase tracking-wider text-stone-400">{{ $room['name'] }}</div>
                @foreach($room['tables'] as $table)
                    <div class="flex items-stretch border-t border-stone-50">
                        <div class="w-40 flex-none py-2 pr-2 text-xs">
                            <span class="font-semibold text-stone-700">{{ $table['name'] }}</span>
                            <span class="text-stone-400"> · {{ $table['capacity'] }}</span>
                        </div>
                        <div class="relative flex-1" style="min-height:2.25rem">
                            {{-- hour gridlines --}}
                            @foreach($t['hours'] as $h)
                                @php($pct = round($t['dayStart']->diffInMinutes($h) / $t['span'] * 100, 2))
                                <span class="absolute top-0 bottom-0 w-px bg-stone-50" style="left:{{ $pct }}%"></span>
                            @endforeach
                            {{-- now line --}}
                            @if($t['nowPct'] !== null)
                                <span class="absolute top-0 bottom-0 z-10 w-px bg-red-400" style="left:{{ $t['nowPct'] }}%"></span>
                            @endif
                            {{-- reservation bars --}}
                            @foreach($table['bars'] as $b)
                                @php($r = $b['reservation'])
                                <a href="{{ route('admin.reservations.show', $r) }}"
                                   class="absolute top-1 bottom-1 z-20 flex items-center overflow-hidden rounded-md px-2 text-[11px] font-semibold text-white shadow-sm transition hover:brightness-110"
                                   style="left:{{ $b['left'] }}%;width:{{ $b['width'] }}%;background:{{ $r->status->value === 'seated' ? '#0f766e' : ($r->status->value === 'requested' ? '#f59e0b' : ($r->status->value === 'completed' ? '#a8a29e' : '#3b82f6')) }}"
                                   title="{{ $b['label'] }}">
                                    <span class="truncate">{{ $r->localStart()->format('H:i') }} {{ $r->guest_name_snapshot }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endforeach

            {{-- Reservations without a fixed table --}}
            @if(count($t['unassigned']))
                <div class="mt-3 mb-1 ml-40 text-[10px] font-bold uppercase tracking-wider text-amber-500">Ohne feste Tischzuweisung</div>
                <div class="flex items-stretch border-t border-stone-50">
                    <div class="w-40 flex-none py-2 pr-2 text-xs text-stone-400">automatisch</div>
                    <div class="relative flex-1" style="min-height:2.25rem">
                        @foreach($t['unassigned'] as $b)
                            @php($r = $b['reservation'])
                            <a href="{{ route('admin.reservations.show', $r) }}"
                               class="absolute top-1 bottom-1 z-20 flex items-center overflow-hidden rounded-md border border-dashed border-amber-300 bg-amber-50 px-2 text-[11px] font-semibold text-amber-800"
                               style="left:{{ $b['left'] }}%;width:{{ $b['width'] }}%" title="{{ $b['label'] }}">
                                <span class="truncate">{{ $r->localStart()->format('H:i') }} {{ $r->guest_name_snapshot }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-4 text-xs text-stone-400">
        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded" style="background:#3b82f6"></span>bestätigt</span>
        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded" style="background:#f59e0b"></span>Anfrage</span>
        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded" style="background:#0f766e"></span>sitzt</span>
        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded" style="background:#a8a29e"></span>abgeschlossen</span>
        <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-px bg-red-400"></span>jetzt</span>
    </div>
    @endif
</div>

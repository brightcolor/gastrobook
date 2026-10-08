{{-- Hauptnavigation: Gruppen aus App\Support\Navigation, darunter „Einstellungen“. --}}
@foreach($groups as $group)
    <div class="mt-4 first:mt-0">
        @if($group->label)
            <p class="nav-heading px-3 pb-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-stone-400">{{ $group->label }}</p>
        @endif
        <div class="space-y-0.5">
            @foreach($group->items as $item)
                <a href="{{ $item->url }}" data-nav-key="{{ $item->key }}"
                   @if($item->active) aria-current="page" @endif
                   class="flex min-h-9 items-center gap-2.5 rounded-md px-3 py-2 {{ $item->active ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
                    <x-nav-icon :name="$item->icon" /><span class="nav-label">{{ $item->label }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endforeach
@if($settingsUrl)
    <div class="mt-4 border-t border-stone-700 pt-4">
        <a href="{{ $settingsUrl }}" data-nav-key="settings"
           @if($settingsActive) aria-current="page" @endif
           class="flex min-h-9 items-center gap-2.5 rounded-md px-3 py-2 {{ $settingsActive ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
            <x-nav-icon name="settings" /><span class="nav-label">Einstellungen</span>
        </a>
    </div>
@endif

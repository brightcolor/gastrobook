{{-- Einstellungsnavigation: Zurück, Suche, Gruppen (Suche: resources/js/admin/nav.js). --}}
<a href="{{ route('admin.dashboard') }}"
   class="mb-3 flex min-h-9 items-center gap-2 rounded-md px-2 text-sm text-stone-300 hover:bg-stone-800 hover:text-white">
    <x-nav-icon name="back" /> Zurück zum Betrieb
</a>
<div class="relative mb-2">
    <x-nav-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
    <label for="{{ $searchId }}" class="sr-only">Einstellungen durchsuchen</label>
    <input id="{{ $searchId }}" type="search" data-settings-search aria-controls="{{ $searchId }}-list"
           autocomplete="off" placeholder="Einstellungen durchsuchen"
           class="h-9 w-full rounded-md border border-stone-700 bg-stone-800 pl-8 pr-2 text-sm text-white placeholder:text-stone-400">
</div>
<div id="{{ $searchId }}-list">
    @foreach($groups as $group)
        <div data-settings-group class="mt-4 first:mt-2">
            @if($group->label)
                <p class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-stone-400">{{ $group->label }}</p>
            @endif
            @foreach($group->items as $item)
                <a href="{{ $item->url }}" data-settings-item data-nav-key="{{ $item->key }}"
                   data-label="{{ $item->label }}" data-keywords="{{ implode('|', $item->keywords) }}"
                   @if($item->active) aria-current="page" @endif
                   class="block rounded-md px-2 py-1.5 {{ $item->active ? 'bg-stone-700 font-semibold text-white' : 'text-stone-200 hover:bg-stone-800' }}">
                    {{ $item->label }}
                    <small data-settings-hit hidden class="block text-xs font-normal text-teal-300"></small>
                </a>
            @endforeach
        </div>
    @endforeach
    <p data-settings-noresult role="status" class="mt-4 px-2 text-sm text-stone-400 empty:mt-0"></p>
</div>

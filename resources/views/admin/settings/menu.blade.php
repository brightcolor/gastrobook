@extends('layouts.admin')
@section('title', 'Einstellungen')

@section('content')
<h1 class="mb-4 text-2xl font-bold tracking-tight">Einstellungen</h1>

<div class="relative mb-6 max-w-xl">
    <x-nav-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
    <label for="settingsSearchPage" class="sr-only">Einstellungen durchsuchen</label>
    <input id="settingsSearchPage" type="search" data-settings-search aria-controls="settingsSearchPage-list"
           data-examples="{{ implode('|', (array) config('navigation.search_examples')) }}"
           autocomplete="off" placeholder="Einstellungen durchsuchen" class="w-full pl-9">
</div>

<div id="settingsSearchPage-list" class="max-w-xl space-y-6">
    @foreach($groups as $group)
        <section data-settings-group>
            @if($group->label)
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-stone-600">{{ $group->label }}</h2>
            @endif
            <div class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                @foreach($group->items as $item)
                    <a href="{{ $item->url }}" data-settings-item data-nav-key="{{ $item->key }}"
                       data-label="{{ $item->label }}" data-keywords="{{ implode('|', $item->keywords) }}"
                       class="flex min-h-12 items-center justify-between gap-3 px-4 py-3 hover:bg-stone-50 focus-visible:bg-stone-50 focus-visible:outline-offset-[-2px]">
                        <span>
                            <span class="block font-medium">{{ $item->label }}</span>
                            <small data-settings-hit hidden class="block text-xs text-teal-800"></small>
                        </span>
                        <x-nav-icon name="chevron" class="h-4 w-4 text-stone-400" />
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
    <p data-settings-noresult role="status" class="text-sm text-stone-600"></p>
</div>
@endsection

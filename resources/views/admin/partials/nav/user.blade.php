{{-- Nutzerbereich unten in der Seitenleiste und im Blatt „Mehr“. --}}
<div class="border-t border-stone-700 p-4 text-sm">
    @if(session('impersonating_tenant_id'))
        <form method="POST" action="{{ route('saas.stop-impersonation') }}" class="nav-rail-only mb-2">
            @csrf
            <button type="submit" title="Supportzugriff beenden"
                    class="flex h-9 w-9 items-center justify-center rounded-md bg-amber-600 text-white hover:bg-amber-500">
                <x-nav-icon name="back" /><span class="sr-only">Supportzugriff beenden</span>
            </button>
        </form>
    @endif
    <a href="{{ route('admin.account.show') }}" title="Mein Konto"
       class="nav-rail-only h-9 w-9 items-center justify-center rounded-md text-stone-300 hover:bg-stone-800 hover:text-white">
        <x-nav-icon name="user" /><span class="sr-only">Mein Konto</span>
    </a>
    <div class="nav-full">
        <div class="mb-2 truncate text-stone-300">{{ $user->name }}</div>
        @if(session('impersonating_tenant_id'))
            <form method="POST" action="{{ route('saas.stop-impersonation') }}" class="mb-2">
                @csrf
                <button class="w-full rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold">Supportzugriff beenden</button>
            </form>
        @endif
        <div class="flex flex-col gap-1">
            <a href="{{ route('admin.account.show') }}"
               class="block rounded-md px-3 py-1.5 text-center text-xs text-stone-300 hover:bg-stone-700 hover:text-white">Mein Konto</a>
            @if($user->isSaasAdmin())
                <a href="{{ route('saas.dashboard') }}"
                   class="block rounded-md px-3 py-1.5 text-center text-xs text-stone-300 hover:bg-stone-700 hover:text-white">Plattform-Admin</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full rounded-md bg-stone-700 px-3 py-1.5 text-xs hover:bg-stone-600">Abmelden</button>
            </form>
        </div>
    </div>
</div>

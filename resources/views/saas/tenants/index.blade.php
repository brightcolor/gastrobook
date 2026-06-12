<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SaaS-Admin – GastroBook</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100 p-6">
<div class="mx-auto max-w-6xl">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold">🏢 SaaS-Verwaltung</h1>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg bg-stone-200 px-4 py-2 text-sm font-semibold">Abmelden</button></form>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-100 text-left text-xs uppercase text-stone-500">
                        <tr>
                            <th class="px-4 py-3">Mandant</th>
                            <th class="px-4 py-3">Tarif</th>
                            <th class="px-4 py-3">Standorte</th>
                            <th class="px-4 py-3">Benutzer</th>
                            <th class="px-4 py-3">Res./Monat</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-50">
                        @foreach($tenants as $tenant)
                            <tr>
                                <td class="px-4 py-3"><strong>{{ $tenant->name }}</strong><div class="text-xs text-stone-400">{{ $tenant->slug }}</div></td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('saas.tenants.plan', $tenant) }}">
                                        @csrf @method('PUT')
                                        <select name="plan_id" onchange="this.form.submit()" class="rounded-lg border-stone-200 text-xs">
                                            @foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($tenant->plan_id === $plan->id)>{{ $plan->name }}</option>@endforeach
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3">{{ $tenant->locations_count }}</td>
                                <td class="px-4 py-3">{{ $tenant->memberships_count }}</td>
                                <td class="px-4 py-3">{{ $reservationCounts[$tenant->id] ?? 0 }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('saas.tenants.status', $tenant) }}">
                                        @csrf @method('PUT')
                                        <select name="status" onchange="this.form.submit()" class="rounded-lg border-stone-200 text-xs">
                                            @foreach(['active' => 'Aktiv', 'suspended' => 'Gesperrt', 'cancelled' => 'Gekündigt'] as $val => $label)
                                                <option value="{{ $val }}" @selected($tenant->status === $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('saas.tenants.impersonate', $tenant) }}">
                                        @csrf
                                        <input type="hidden" name="reason" value="Support">
                                        <button class="rounded-lg bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800">Supportzugriff</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $tenants->links() }}</div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="font-bold">Mandant anlegen</h2>
            <form method="POST" action="{{ route('saas.tenants.store') }}" class="mt-3 space-y-3 text-sm">
                @csrf
                <input type="text" name="name" required placeholder="Name (z. B. Restaurantgruppe Müller)" class="w-full rounded-lg border-stone-200">
                <select name="plan_id" required class="w-full rounded-lg border-stone-200">
                    @foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }}</option>@endforeach
                </select>
                <input type="text" name="owner_name" required placeholder="Inhaber: Name" class="w-full rounded-lg border-stone-200">
                <input type="email" name="owner_email" required placeholder="Inhaber: E-Mail" class="w-full rounded-lg border-stone-200">
                <input type="text" name="location_name" required placeholder="Erster Standort (z. B. Restaurant Sonne)" class="w-full rounded-lg border-stone-200">
                <button class="w-full rounded-xl bg-stone-900 py-2.5 font-bold text-white">Anlegen</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>

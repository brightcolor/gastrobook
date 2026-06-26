<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingRequest;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

class ShowStats extends Command
{
    protected $signature = 'swayy:stats';

    protected $description = 'Plattform-Überblick: Mandanten, Nutzer, Gäste, Reservierungen.';

    public function handle(): int
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $rows = [
            ['Mandanten gesamt', Tenant::count()],
            ['  davon aktiv', (int) $byStatus->get('active', 0)],
            ['  davon Trial abgelaufen', (int) $byStatus->get('trial_expired', 0)],
            ['  davon Billing ausstehend', (int) $byStatus->get('pending_billing', 0)],
            ['  davon gesperrt/gekündigt', (int) $byStatus->get('suspended', 0) + (int) $byStatus->get('cancelled', 0)],
            ['Nutzer gesamt', User::count()],
            ['  davon Plattform-Admins', User::whereNotNull('saas_role')->count()],
            ['Gäste (nicht anonymisiert)', Guest::withoutGlobalScopes()->where('anonymized', false)->count()],
            ['Reservierungen gesamt', Reservation::withoutGlobalScopes()->count()],
            ['  davon künftig', Reservation::withoutGlobalScopes()->where('start_at', '>=', now())->count()],
            ['Billing-Anfragen offen', BillingRequest::whereNotNull('confirmed_at')
                ->whereHas('tenant', fn ($q) => $q->where('status', '!=', 'active'))->count()],
        ];

        $this->newLine();
        $this->table(['Kennzahl', 'Wert'], $rows);

        return self::SUCCESS;
    }
}

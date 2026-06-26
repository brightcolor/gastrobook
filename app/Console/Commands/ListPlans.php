<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;

class ListPlans extends Command
{
    protected $signature = 'swayy:plans {--all : Auch inaktive Tarife anzeigen}';

    protected $description = 'Listet die Tarife (Pläne) im Terminal.';

    public function handle(): int
    {
        $query = Plan::withCount('tenants')->orderBy('sort_order');

        if (! $this->option('all')) {
            $query->where('is_active', true);
        }

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->components->warn('Keine Tarife gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Name', 'Preis/Monat', 'Trial-Tage', 'Aktiv', 'Mandanten'],
            $plans->map(fn (Plan $p) => [
                $p->key,
                $p->name,
                number_format($p->price_monthly_minor / 100, 2, ',', '.').' '.$p->currency,
                $p->trial_days,
                $p->is_active ? 'ja' : 'nein',
                $p->tenants_count,
            ])->all()
        );

        return self::SUCCESS;
    }
}

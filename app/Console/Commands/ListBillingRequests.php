<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingRequest;
use Illuminate\Console\Command;

class ListBillingRequests extends Command
{
    protected $signature = 'swayy:billing-requests
        {--pending : Nur bestätigte, aber noch nicht aktivierte Anfragen}';

    protected $description = 'Listet eingegangene Billing-Anfragen (nach Trial-Ablauf).';

    public function handle(): int
    {
        $query = BillingRequest::with('tenant')->latest();

        if ($this->option('pending')) {
            $query->whereNotNull('confirmed_at')
                ->whereHas('tenant', fn ($q) => $q->where('status', '!=', 'active'));
        }

        $requests = $query->get();

        if ($requests->isEmpty()) {
            $this->components->warn('Keine Billing-Anfragen gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Mandant', 'Kontakt', 'E-Mail', 'Tarif', 'Bestätigt', 'Mandant-Status', 'Eingegangen'],
            $requests->map(fn (BillingRequest $r) => [
                $r->id,
                $r->tenant?->name ?? '—',
                $r->contact_name,
                $r->contact_email,
                $r->plan_key,
                $r->confirmed_at?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'offen',
                $r->tenant?->status ?? '—',
                $r->created_at?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '—',
            ])->all()
        );

        $this->components->info($requests->count().' Anfragen.');

        return self::SUCCESS;
    }
}

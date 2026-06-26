<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guest;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ListGuests extends Command
{
    protected $signature = 'swayy:guests
        {--tenant= : Nur Kunden dieses Mandanten (ID oder Slug)}
        {--search= : Filter nach Name, E-Mail oder Telefon}
        {--limit=50 : Maximale Anzahl Zeilen (0 = alle)}
        {--with-anonymized : Auch anonymisierte (gelöschte) Kunden anzeigen}';

    protected $description = 'Listet angelegte Kunden (Gäste) im Terminal.';

    public function handle(): int
    {
        // CLI hat keinen Tenant-Kontext → globalen TenantScope umgehen und
        // optional selbst nach Mandant filtern.
        $query = Guest::withoutGlobalScopes()->orderBy('last_name')->orderBy('first_name');

        if ($tenantOpt = $this->option('tenant')) {
            $tenant = is_numeric($tenantOpt)
                ? Tenant::find((int) $tenantOpt)
                : Tenant::where('slug', $tenantOpt)->first();

            if (! $tenant) {
                $this->components->error("Mandant '{$tenantOpt}' nicht gefunden.");

                return self::FAILURE;
            }

            $query->where('tenant_id', $tenant->id);
            $this->components->info("Mandant: {$tenant->name} (#{$tenant->id})");
        }

        if ($search = $this->option('search')) {
            $query->search($search);
        }

        if (! $this->option('with-anonymized')) {
            $query->where('anonymized', false);
        }

        $total = (clone $query)->count();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $guests = $query->get();

        if ($guests->isEmpty()) {
            $this->components->warn('Keine Kunden gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['Mandant', 'Name', 'E-Mail', 'Telefon', 'Besuche', 'Letzter Besuch', 'Angelegt'],
            $guests->map(fn (Guest $g) => [
                $g->tenant_id,
                trim($g->first_name.' '.$g->last_name) ?: '—',
                $g->email ?: '—',
                $g->phone ?: '—',
                (int) ($g->visit_count ?? 0),
                $g->last_visit_at?->format('d.m.Y') ?? '—',
                $g->created_at?->format('d.m.Y') ?? '—',
            ])->all()
        );

        $shown = $guests->count();
        $this->components->info(
            $limit > 0 && $total > $shown
                ? "{$shown} von {$total} Kunden angezeigt (Limit {$limit}; --limit=0 für alle)."
                : "{$total} Kunden."
        );

        return self::SUCCESS;
    }
}

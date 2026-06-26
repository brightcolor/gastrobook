<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Console\Command;

class ListReservations extends Command
{
    protected $signature = 'swayy:reservations
        {--tenant= : Nur Reservierungen dieses Mandanten (ID oder Slug)}
        {--date= : Nur dieses Datum (Y-m-d)}
        {--upcoming : Nur künftige Reservierungen}
        {--status= : Nur dieser Status (z. B. confirmed, requested)}
        {--limit=50 : Maximale Anzahl Zeilen (0 = alle)}';

    protected $description = 'Listet Reservierungen über alle Mandanten im Terminal.';

    public function handle(): int
    {
        $query = Reservation::withoutGlobalScopes()
            ->with('tenant')
            ->orderBy('start_at');

        if ($tenantOpt = $this->option('tenant')) {
            $tenant = is_numeric($tenantOpt)
                ? Tenant::find((int) $tenantOpt)
                : Tenant::where('slug', $tenantOpt)->first();

            if (! $tenant) {
                $this->components->error("Mandant '{$tenantOpt}' nicht gefunden.");

                return self::FAILURE;
            }

            $query->where('tenant_id', $tenant->id);
        }

        if ($date = $this->option('date')) {
            $query->whereDate('reservation_date', $date);
        }

        if ($this->option('upcoming')) {
            $query->where('start_at', '>=', now());
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $reservations = $query->get();

        if ($reservations->isEmpty()) {
            $this->components->warn('Keine Reservierungen gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Mandant', 'Datum/Zeit', 'Pers.', 'Gast', 'Status'],
            $reservations->map(fn (Reservation $r) => [
                $r->code,
                $r->tenant?->name ?? '—',
                $r->start_at->copy()->setTimezone($r->timezone ?: 'Europe/Berlin')->format('d.m.Y H:i'),
                $r->party_size,
                $r->guest_name_snapshot ?: '—',
                $r->status->value,
            ])->all()
        );

        $shown = $reservations->count();
        $this->components->info(
            $limit > 0 && $total > $shown
                ? "{$shown} von {$total} Reservierungen (Limit {$limit}; --limit=0 für alle)."
                : "{$total} Reservierungen."
        );

        return self::SUCCESS;
    }
}

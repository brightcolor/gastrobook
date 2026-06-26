<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ListTenants extends Command
{
    protected $signature = 'swayy:tenants
        {--status= : Nur dieser Status (active, trial_expired, pending_billing, suspended, cancelled)}
        {--search= : Filter nach Name oder Slug}
        {--with-trashed : Auch gelöschte (soft-deleted) Mandanten anzeigen}';

    protected $description = 'Listet die Mandanten (deine Kunden) im Terminal.';

    public function handle(): int
    {
        $query = Tenant::with('plan')
            ->withCount('users')
            ->orderBy('name');

        if ($this->option('with-trashed')) {
            $query->withTrashed();
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        if ($search = $this->option('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->components->warn('Keine Mandanten gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Typ', 'Status', 'Tarif', 'Trial-Ende', 'Nutzer', 'Owner', 'Angelegt'],
            $tenants->map(fn (Tenant $t) => [
                $t->id,
                $t->name,
                $t->slug,
                $t->type->value,
                $t->trashed() ? 'gelöscht' : $t->status,
                $t->plan?->name ?? '—',
                $t->trial_ends_at?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '—',
                $t->users_count,
                $this->ownerEmail($t),
                $t->created_at?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '—',
            ])->all()
        );

        $this->components->info($tenants->count().' Mandanten.');

        return self::SUCCESS;
    }

    private function ownerEmail(Tenant $tenant): string
    {
        $owner = $tenant->users()
            ->wherePivot('role', 'tenant_owner')
            ->first()
            ?? $tenant->users()->first();

        return $owner?->email ?? '—';
    }
}

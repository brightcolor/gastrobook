<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = 'swayy:users
        {--tenant= : Nur Nutzer dieses Mandanten (ID oder Slug)}
        {--saas : Nur Plattform-Admins (saas_role gesetzt)}
        {--search= : Filter nach Name oder E-Mail}';

    protected $description = 'Listet alle Nutzer über alle Mandanten hinweg im Terminal.';

    public function handle(): int
    {
        $query = User::with(['tenants:id,name,slug'])->orderBy('name');

        if ($this->option('saas')) {
            $query->whereNotNull('saas_role');
        }

        if ($search = $this->option('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($tenantOpt = $this->option('tenant')) {
            $tenant = is_numeric($tenantOpt)
                ? Tenant::find((int) $tenantOpt)
                : Tenant::where('slug', $tenantOpt)->first();

            if (! $tenant) {
                $this->components->error("Mandant '{$tenantOpt}' nicht gefunden.");

                return self::FAILURE;
            }

            $query->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id));
            $this->components->info("Mandant: {$tenant->name} (#{$tenant->id})");
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->components->warn('Keine Nutzer gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'E-Mail', 'Plattform-Rolle', 'Mandanten (Rolle)', 'Angelegt'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->name,
                $u->email,
                $u->saas_role ?? '—',
                $this->memberships($u),
                $u->created_at?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '—',
            ])->all()
        );

        $this->components->info($users->count().' Nutzer.');

        return self::SUCCESS;
    }

    private function memberships(User $user): string
    {
        if ($user->tenants->isEmpty()) {
            return '—';
        }

        return $user->tenants
            ->map(fn (Tenant $t) => $t->name.' ('.($t->pivot->role ?? '?').')')
            ->implode(', ');
    }
}

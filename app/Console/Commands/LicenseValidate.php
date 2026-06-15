<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

class LicenseValidate extends Command
{
    protected $signature = 'license:validate {--fresh : Bypass cache and recheck now}';

    protected $description = 'Validates the Swayy self-hosted license and shows its status.';

    public function handle(LicenseService $service): int
    {
        if ($this->option('fresh')) {
            $service->forget();
        }

        $status = $service->check();

        if (! $status->selfHosted) {
            $this->components->info('SWAYY_SELF_HOSTED is not set — license enforcement is disabled (hosted SaaS mode).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Swayy License Status</>');
        $this->line(str_repeat('─', 42));
        $this->line('  Lizenznehmer : '.($status->licensee ?: '–'));
        $this->line('  Lizenz-ID    : '.($status->licenseId ?: '–'));
        $this->line('  Plan         : '.$status->plan);
        $this->line('  Gültig bis   : '.($status->expiresAt?->toDateString() ?? 'unbegrenzt'));

        if ($status->daysLeft() !== null) {
            $days = $status->daysLeft();
            $color = $days <= 0 ? 'red' : ($days <= 30 ? 'yellow' : 'green');
            $label = $days >= 0 ? "{$days} Tage" : abs($days).' Tage abgelaufen';
            $this->line("  Verbleibend  : <fg={$color}>{$label}</>");
        }

        $this->line('  Grace-Period : '.($status->inGracePeriod ? '<fg=yellow>JA</>' : 'nein'));
        $this->line('  Widerrufen   : '.($status->revoked ? '<fg=red>JA</>' : 'nein'));
        $this->newLine();

        if ($status->valid || $status->inGracePeriod) {
            $emoji = $status->inGracePeriod ? '⚠️' : '✓';
            $msg = $status->inGracePeriod
                ? 'Lizenz in Kulanzfrist. Bitte erneuern Sie die Lizenz.'
                : 'Lizenz gültig.';
            $this->components->info("{$emoji}  {$msg}");

            return self::SUCCESS;
        }

        $this->components->error('✗  '.$status->error);

        return self::FAILURE;
    }
}

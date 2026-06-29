<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class QueueHealth extends Command
{
    protected $signature = 'swayy:queue-health {--queue=default : Zu prüfender Queue-Name}';

    protected $description = 'Zeigt Queue-Verbindung, wartende und fehlgeschlagene Jobs auf einen Blick.';

    public function handle(): int
    {
        $queue = (string) $this->option('queue');

        $this->line('Connection: '.config('queue.default'));
        $this->line('Queue:      '.$queue);

        // Pending jobs (works for redis & database connections).
        try {
            $this->line('Wartend:    '.Queue::size($queue));
        } catch (\Throwable $e) {
            $this->error('Wartend:    nicht ermittelbar ('.$e->getMessage().')');
            $this->warn('→ Queue-Backend (Redis?) nicht erreichbar – Jobs können nicht verarbeitet werden.');
        }

        // Failed jobs.
        try {
            $failed = DB::table('failed_jobs')->count();
            $this->line('Fehlerhaft: '.$failed);

            if ($failed > 0) {
                $this->newLine();
                $this->warn('Letzte fehlgeschlagene Jobs:');
                DB::table('failed_jobs')->latest('failed_at')->limit(5)->get()
                    ->each(function ($row) {
                        $firstLine = strtok((string) $row->exception, "\n");
                        $this->line(sprintf('  [%s] %s', $row->failed_at, mb_substr($firstLine, 0, 140)));
                    });
                $this->newLine();
                $this->line('Erneut versuchen: php artisan queue:retry all   |   Verwerfen: php artisan queue:flush');
            }
        } catch (\Throwable $e) {
            $this->error('Fehlerhaft: failed_jobs nicht lesbar ('.$e->getMessage().')');
        }

        return self::SUCCESS;
    }
}

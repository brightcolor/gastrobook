<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class InstallLegalDocuments extends Command
{
    protected $signature = 'swayy:install-legal {--force : Vorhandene Dateien überschreiben}';

    protected $description = 'Legt Impressum/Datenschutz/AGB als Markdown unter storage/app/legal an, falls sie fehlen.';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        foreach (array_keys(config('swayy.legal.documents')) as $key) {
            $target = "legal/{$key}.md";

            if ($disk->exists($target) && ! $this->option('force')) {
                continue;
            }

            $source = resource_path("legal/{$key}.md");
            if (! is_file($source)) {
                $this->components->warn("Vorlage fehlt: {$source}");

                continue;
            }

            $disk->put($target, (string) file_get_contents($source));
            $this->components->info("Angelegt: storage/app/{$target}");
        }

        return self::SUCCESS;
    }
}

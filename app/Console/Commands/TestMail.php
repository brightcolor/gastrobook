<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMail extends Command
{
    protected $signature = 'swayy:test-mail {email : Empfänger der Testmail}';

    protected $description = 'Sendet eine Testmail SYNCHRON (ohne Queue) – trennt Mail-Config- von Queue-Worker-Problemen.';

    public function handle(): int
    {
        $to = (string) $this->argument('email');

        $this->line('Mailer:     '.config('mail.default'));
        $this->line('Host:       '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('From:       '.config('mail.from.address'));
        $this->line('Queue:      '.config('queue.default').' (Testmail wird bewusst SYNCHRON gesendet)');
        $this->newLine();

        try {
            Mail::raw('Swayy Testmail – wenn du das liest, funktioniert der SMTP-Versand.', function ($m) use ($to) {
                $m->to($to)->subject('Swayy Testmail');
            });
        } catch (\Throwable $e) {
            $this->error('FEHLER beim Versand: '.$e->getMessage());
            $this->newLine();
            $this->warn('→ Das ist ein MAIL-CONFIG-Problem (SMTP), nicht die Queue. Prüfe MAIL_* in der .env.');

            return self::FAILURE;
        }

        $this->info('✔ Testmail synchron versendet an '.$to);
        $this->newLine();
        $this->line('Kam sie an? → SMTP ist ok. Wenn echte App-Mails (Reset/Buchung) dennoch fehlen,');
        $this->line('  liegt es an der QUEUE: prüfe `php artisan queue:failed` und die Logs des queue-Containers.');
        $this->line('Kam sie NICHT an? → SMTP nimmt an, stellt aber nicht zu (Spam/Absender/Relay) – beim Provider prüfen.');

        return self::SUCCESS;
    }
}

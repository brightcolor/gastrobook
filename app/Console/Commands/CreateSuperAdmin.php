<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    protected $signature = 'swayy:create-admin
        {--email= : E-Mail des Oberadmins}
        {--password= : Passwort (min. 10 Zeichen)}
        {--name= : Anzeigename}
        {--force : Bestehenden Account auf Oberadmin setzen / Passwort zurücksetzen}
        {--if-missing : Nur anlegen, wenn noch KEIN Oberadmin existiert (für ersten Start)}';

    protected $description = 'Legt einen Plattform-Oberadmin (saas_role=super_admin) an.';

    public function handle(): int
    {
        // Boot-Modus: existiert bereits ein Oberadmin, nichts tun.
        if ($this->option('if-missing') && User::where('saas_role', 'super_admin')->exists()) {
            return self::SUCCESS;
        }

        // Werte aus Optionen, sonst Config/Env (für nicht-interaktiven ersten Start), sonst Prompt.
        $email = $this->option('email') ?: config('swayy.admin.email');
        $password = $this->option('password') ?: config('swayy.admin.password');
        $name = $this->option('name') ?: config('swayy.admin.name') ?: 'Administrator';

        // Im Boot-Modus ohne Daten: sauber überspringen, Start nicht abbrechen.
        if ($this->option('if-missing') && (! $email || ! $password)) {
            $this->components->warn('Kein Oberadmin vorhanden. Setze SWAYY_ADMIN_EMAIL und SWAYY_ADMIN_PASSWORD oder lege ihn per "php artisan swayy:create-admin" an.');

            return self::SUCCESS;
        }

        if (! $email && $this->input->isInteractive()) {
            $email = $this->ask('E-Mail des Oberadmins');
        }
        if (! $password && $this->input->isInteractive()) {
            $password = $this->secret('Passwort (min. 10 Zeichen)');
        }

        $existing = $email ? User::where('email', $email)->first() : null;

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email', $existing && $this->option('force') ? 'string' : 'unique:users,email'],
                'password' => ['required', 'string', 'min:10'],
                'name' => ['required', 'string', 'max:120'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }
            if ($existing && ! $this->option('force')) {
                $this->components->info('Tipp: Mit --force wird der bestehende Account zum Oberadmin gemacht und das Passwort gesetzt.');
            }

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update([
                'name' => $name,
                'password' => $password, // hashed cast
                'saas_role' => 'super_admin',
                'is_active' => true,
            ]);
            $this->components->info("Bestehender Account {$email} ist jetzt Oberadmin (Passwort gesetzt).");

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed cast
            'saas_role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->components->info("Oberadmin {$email} angelegt. Login unter /login.");

        return self::SUCCESS;
    }
}

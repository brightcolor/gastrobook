<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Einmalige Anmeldelinks fuer den Inhaber eines Betriebs mit Vertrag.
 *
 * Gespeichert ist nur die Pruefsumme des Zufallswerts. Ein Link gilt einmal
 * und LOGIN_LINK_SECONDS lang; danach erklaert eine Seite den Weg zurueck in
 * den Kundenbereich, wo „Zum Dienst“ einen neuen holt.
 */
final class LoginLinks
{
    /**
     * @return array{url: string, expires_at: CarbonImmutable}
     */
    public function issue(User $user, Tenant $tenant): array
    {
        $wert = Str::random(48);
        $ablauf = CarbonImmutable::now()->addSeconds((int) config('provisioning.login_link_seconds', 120));

        DB::table('provisioning_login_links')->insert([
            'token_hash' => hash('sha256', $wert),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'expires_at' => $ablauf,
            'created_at' => now(),
        ]);

        return ['url' => route('provisioning.login', ['token' => $wert]), 'expires_at' => $ablauf];
    }

    /**
     * Loest einen Link ein: der Eintrag, wenn er gilt, sonst null. Ein gueltiger
     * Link ist danach verbraucht, auch wenn die Anmeldung danach scheitert.
     *
     * @return object{user_id: int, tenant_id: int}|null
     */
    public function redeem(string $token): ?object
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($token) {
            $eintrag = DB::table('provisioning_login_links')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            if ($eintrag === null || $eintrag->used_at !== null || CarbonImmutable::parse($eintrag->expires_at)->isPast()) {
                return null;
            }
            DB::table('provisioning_login_links')->where('id', $eintrag->id)->update(['used_at' => now()]);

            return $eintrag;
        });
    }

    /** Loescht abgelaufene Links; liefert die Zahl. */
    public function cleanup(): int
    {
        return DB::table('provisioning_login_links')->where('expires_at', '<', now())->delete();
    }
}

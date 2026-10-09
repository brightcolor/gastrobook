<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Wer welche Rolle vergeben darf - beim Einladen und beim Aendern einer Rolle.
 *
 * - Inhaber und SaaS-Admins vergeben jede Rolle.
 * - Wer Rollen verwalten darf (users.roles.manage), vergibt jede Rolle ausser
 *   der Inhaberrolle. Sie schliesst Abrechnung und "Betrieb loeschen" ein.
 * - Wer nur einladen darf, vergibt nur Rollen, deren Rechte er selbst alle
 *   hat. Sonst laedt die Betriebsleitung eine zweite eigene Mailadresse als
 *   Administration ein und hat danach volle Rechte.
 *
 * Die Grenze ergibt sich allein aus den Rollenrechten in config/permissions.php.
 * Eine neue Rolle oder ein geaendertes Recht wirkt hier ohne weitere Eintragung.
 */
final class RoleAssignment
{
    /**
     * Alle Rollen, die $user in $tenant vergeben darf.
     *
     * @return array<int, string>
     */
    public static function assignable(User $user, Tenant $tenant): array
    {
        return array_values(array_filter(
            array_keys(self::roles()),
            fn (string $role): bool => self::refusal($user, $tenant, $role) === null,
        ));
    }

    /**
     * Warum $user die Rolle $role in $tenant nicht vergeben darf, als Meldung
     * fuer die Oberflaeche. Null, wenn er es darf.
     */
    public static function refusal(User $user, Tenant $tenant, string $role): ?string
    {
        $roles = self::roles();
        if (! array_key_exists($role, $roles)) {
            return __('Diese Rolle gibt es nicht. Bitte eine Rolle aus der Liste wählen.');
        }

        $own = $user->membershipFor($tenant)?->role;
        if ($user->isSaasAdmin() || $own === 'tenant_owner') {
            return null;
        }

        if ($role === 'tenant_owner') {
            return __('Diese Rolle kannst du nicht vergeben: Zum Inhaber ernennt nur ein Inhaber des Betriebs. Bitte einen Inhaber darum.');
        }

        if ($user->canInTenant('users.roles.manage', $tenant)) {
            return null;
        }

        $ownPermissions = $own !== null ? (array) ($roles[$own] ?? []) : [];
        if (! self::covers($ownPermissions, (array) $roles[$role])) {
            return __('Diese Rolle kannst du nicht vergeben, weil sie Rechte enthält, die deine eigene Rolle nicht hat. Wähle eine Rolle mit gleichen oder weniger Rechten. Soll die Person mehr dürfen, lädt sie jemand ein, der Rollen verwalten darf.');
        }

        return null;
    }

    /**
     * Hat $own jedes Recht aus $target? '*' steht fuer alle Rechte.
     *
     * @param  array<int, string>  $own
     * @param  array<int, string>  $target
     */
    private static function covers(array $own, array $target): bool
    {
        if (in_array('*', $own, true)) {
            return true;
        }

        if (in_array('*', $target, true)) {
            return false;
        }

        return array_diff($target, $own) === [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function roles(): array
    {
        return (array) config('permissions.roles', []);
    }
}

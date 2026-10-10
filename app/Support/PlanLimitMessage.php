<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Meldung, wenn der Tarif keinen weiteren Tisch zulässt: die Grenze und ein
 * nächster Schritt, den die Person selbst gehen kann.
 */
final class PlanLimitMessage
{
    public static function tables(Tenant $tenant, User $user): string
    {
        $limit = (int) $tenant->limit('max_tables');

        return trans_choice(
            '{0} Dein Tarif umfasst keine Tische.|{1} Dein Tarif umfasst einen Tisch, und der ist schon angelegt.|[2,*] Dein Tarif umfasst :count Tische, und alle sind schon angelegt.',
            $limit,
            ['count' => $limit],
        ).' '.($user->canInTenant('billing.manage', $tenant)
            ? __('Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.')
            : __('Für weitere Tische bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.'));
    }
}

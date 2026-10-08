<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Tenant;

/**
 * Die Zustaende eines Betriebs mit Vertrag und ihre Uebergaenge
 * (Skill kundenpanel-saas, zustaende.md).
 *
 * Offen heisst wartend oder aktiv. Gesperrt und gekuendigt sperren die
 * Verwaltung bei jeder Anfrage (EnsureTrialActive) und die API-Tokens
 * (ResolveApiTenant); die oeffentliche Buchung gibt es nur fuer aktive
 * Betriebe. Damit endet jede offene Sitzung mit der naechsten Anfrage.
 *
 * Aendert den Betrieb, schreibt nicht: Der Aufrufer speichert in seiner
 * Transaktion.
 */
final class AccountState
{
    public const PENDING = 'pending_approval';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const SUSPEND_REASONS = ['operator', 'unpaid'];

    public const CANCEL_REASONS = ['customer', 'rejected', 'contract_ended', 'operator'];

    /** Neuer Betrieb aus dem Panel: mit Freigabe aktiv, sonst wartend. */
    public static function start(Tenant $tenant, bool $approved): void
    {
        if ($approved && $tenant->approved_at === null) {
            $tenant->approved_at = now();
        }
        self::set($tenant, self::openState($tenant));
    }

    /** Setzt die Freigabe; nur ein wartender Betrieb wird aktiv. Liefert, ob er aufging. */
    public static function approve(Tenant $tenant): bool
    {
        if ($tenant->status === self::CANCELLED) {
            throw new ProvisioningError(409, 'state', "„{$tenant->name}“ ist gekündigt. Nimm zuerst die Kündigung zurück, dann lässt sich der Betrieb freigeben.");
        }
        if ($tenant->approved_at === null) {
            $tenant->approved_at = now();
        }
        if ($tenant->status !== self::PENDING) {
            return false;
        }
        self::set($tenant, self::ACTIVE);

        return true;
    }

    public static function suspend(Tenant $tenant, string $reason): void
    {
        if ($tenant->status === self::CANCELLED) {
            throw new ProvisioningError(409, 'state', "„{$tenant->name}“ ist gekündigt und damit schon gesperrt.");
        }
        self::set($tenant, self::SUSPENDED, $reason);
    }

    public static function unsuspend(Tenant $tenant): void
    {
        if ($tenant->status === self::CANCELLED) {
            throw new ProvisioningError(409, 'state', "„{$tenant->name}“ ist gekündigt. Nimm zuerst die Kündigung zurück.");
        }
        if ($tenant->status !== self::SUSPENDED) {
            return;
        }
        // Zurueck nach der Freigabe: Was nie freigegeben war, wartet weiter.
        self::set($tenant, self::openState($tenant));
    }

    public static function cancel(Tenant $tenant, string $reason): void
    {
        if ($tenant->status === self::CANCELLED) {
            $tenant->state_reason = $reason;

            return;
        }
        $tenant->state_before = $tenant->status;
        self::set($tenant, self::CANCELLED, $reason);
    }

    public static function uncancel(Tenant $tenant): void
    {
        if ($tenant->status !== self::CANCELLED) {
            return;
        }
        $zurueck = $tenant->state_before === self::SUSPENDED ? self::SUSPENDED : self::openState($tenant);
        $tenant->state_before = null;
        self::set($tenant, $zurueck, $zurueck === self::SUSPENDED ? 'operator' : null);
    }

    /** Offen heisst: Anmeldung und Arbeit in der Verwaltung sind moeglich. */
    public static function isOpen(Tenant $tenant): bool
    {
        return in_array($tenant->status, [self::PENDING, self::ACTIVE], true);
    }

    public static function label(Tenant $tenant): string
    {
        return match ($tenant->status) {
            self::PENDING => 'wartet auf Freigabe',
            self::ACTIVE => 'aktiv',
            self::SUSPENDED => 'gesperrt',
            self::CANCELLED => 'gekündigt',
            default => (string) $tenant->status,
        };
    }

    private static function openState(Tenant $tenant): string
    {
        return $tenant->approved_at !== null ? self::ACTIVE : self::PENDING;
    }

    private static function set(Tenant $tenant, string $state, ?string $reason = null): void
    {
        $tenant->status = $state;
        $tenant->state_reason = $reason;
    }
}

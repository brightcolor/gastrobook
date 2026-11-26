<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Liest den Zustellstatus der Mails einer Reservierung für die Buchungsseite
 * des Gastes und für die Reservierung im Admin.
 */
final class MailDeliveryStatus
{
    public const STATE_PENDING = 'pending';

    public const STATE_DELAYED = 'delayed';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_FAILED = 'failed';

    public function waitsForDelivery(): bool
    {
        return (bool) config('swayy.mail_delivery.wait_for_delivery', false);
    }

    /**
     * Zustand der Mails, die der Gast gerade bekommt: die jüngsten Mails an
     * ihn (ohne Hinweise an den Betrieb), solange sie jünger als
     * recent_minutes sind. null, wenn die Seite nicht warten soll.
     *
     * @return array{state: string, message: string, recipient: string, reference: string}|null
     */
    public function forGuest(Reservation $reservation, Location $location): ?array
    {
        if (! $this->waitsForDelivery()) {
            return null;
        }

        $logs = $this->recentLogs($reservation)->filter(fn (NotificationLog $log) => $log->isForGuest())->values();
        if ($logs->isEmpty()) {
            return null;
        }

        $failed = $logs->first(fn (NotificationLog $log) => $log->hasFailed());
        $state = match (true) {
            $failed !== null => self::STATE_FAILED,
            $logs->every(fn (NotificationLog $log) => $log->status === NotificationLog::STATUS_DELIVERED) => self::STATE_DELIVERED,
            $logs->contains(fn (NotificationLog $log) => $log->status === NotificationLog::STATUS_DELAYED) => self::STATE_DELAYED,
            default => self::STATE_PENDING,
        };

        $log = $failed ?? $logs->first();

        return [
            'state' => $state,
            'message' => $this->guestMessage($state, $log, $reservation, $location),
            'recipient' => (string) $log->recipient,
            'reference' => 'M-'.$log->id,
        ];
    }

    /**
     * Die Mails einer Reservierung für die Liste im Admin, neueste zuerst.
     *
     * @return Collection<int, NotificationLog>
     */
    public function forAdmin(Reservation $reservation): Collection
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('reservation_id', $reservation->id)
            ->where('channel', 'mail')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) config('swayy.mail_delivery.list_max', 50))
            ->get();
    }

    /** Gerade unterwegs und deshalb ein Grund, auf der Seite zu bleiben. */
    public function adminIsWaiting(Reservation $reservation): bool
    {
        return $this->waitsForDelivery()
            && $this->recentLogs($reservation)->contains(fn (NotificationLog $log) => $log->isPending());
    }

    /**
     * Die Zeilen der Admin-Liste als Daten für die Seite, die nachfragt.
     *
     * @return array{waiting: bool, rows: list<array{id: int, status: string, label: string, tone: string, detail: string}>}
     */
    public function adminPayload(Reservation $reservation): array
    {
        return [
            'waiting' => $this->adminIsWaiting($reservation),
            'rows' => $this->forAdmin($reservation)->map(fn (NotificationLog $log) => [
                'id' => $log->id,
                'status' => $log->status,
                'label' => $log->statusLabel(),
                'tone' => $log->statusTone(),
                'detail' => (string) ($log->status_detail ?? $log->error ?? ''),
            ])->values()->all(),
        ];
    }

    /** @return Collection<int, NotificationLog> */
    private function recentLogs(Reservation $reservation): Collection
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('reservation_id', $reservation->id)
            ->where('channel', 'mail')
            ->whereNotNull('message_id')
            ->where('created_at', '>=', now()->subMinutes((int) config('swayy.mail_delivery.recent_minutes', 10)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function guestMessage(string $state, NotificationLog $log, Reservation $reservation, Location $location): string
    {
        $du = $location->effectiveSettings()->du();
        $address = (string) $log->recipient;
        $vorgang = $location->tenant?->isSalon() ? 'Buchung' : 'Reservierung';
        $phone = trim((string) $location->phone);

        return match ($state) {
            self::STATE_PENDING => $du
                ? "Wir schicken dir gerade die E-Mail an {$address}. Bitte bleib auf dieser Seite, bis die Zustellung bestätigt ist. Der Vorgang ist noch nicht abgeschlossen."
                : "Wir schicken Ihnen gerade die E-Mail an {$address}. Bitte bleiben Sie auf dieser Seite, bis die Zustellung bestätigt ist. Der Vorgang ist noch nicht abgeschlossen.",
            self::STATE_DELAYED => $du
                ? "Der Mailserver von {$address} nimmt die E-Mail gerade nicht an. Wir versuchen es automatisch weiter. Bitte bleib noch auf dieser Seite."
                : "Der Mailserver von {$address} nimmt die E-Mail gerade nicht an. Wir versuchen es automatisch weiter. Bitte bleiben Sie noch auf dieser Seite.",
            self::STATE_DELIVERED => "Die E-Mail an {$address} ist zugestellt.",
            default => $this->failureMessage($log, $reservation, $du, $address, $vorgang, $phone),
        };
    }

    private function failureMessage(NotificationLog $log, Reservation $reservation, bool $du, string $address, string $vorgang, string $phone): string
    {
        $reason = match ($this->failureReason($log)) {
            'unknown_address' => 'Diese Adresse gibt es laut dem Mailserver des Empfängers nicht.',
            'mailbox_full' => 'Das Postfach ist voll.',
            'held' => 'Unser Mailserver hält Mails an diese Adresse zurück, weil frühere Mails dort nicht ankamen.',
            default => 'Der Mailserver des Empfängers hat sie abgelehnt.',
        };

        $call = $phone !== ''
            ? ($du ? "ruf uns an: {$phone}" : "rufen Sie uns an: {$phone}")
            : ($du ? 'melde dich direkt bei uns' : 'melden Sie sich direkt bei uns');

        $next = $log->template_key === 'guest_verify'
            ? ($du
                ? "Deine {$vorgang} ist deshalb noch nicht fest. Bitte buche noch einmal mit einer anderen E-Mail-Adresse oder {$call}."
                : "Ihre {$vorgang} ist deshalb noch nicht fest. Bitte buchen Sie noch einmal mit einer anderen E-Mail-Adresse oder {$call}.")
            : ($du
                ? "Deine {$vorgang} {$reservation->code} ist gespeichert, nur die E-Mail dazu kam nicht an. Notiere dir die Nummer, oder {$call}."
                : "Ihre {$vorgang} {$reservation->code} ist gespeichert, nur die E-Mail dazu kam nicht an. Notieren Sie sich die Nummer, oder {$call}.");

        return "Die E-Mail an {$address} konnte nicht zugestellt werden. {$reason} {$next}";
    }

    /** unknown_address, mailbox_full, held oder other. */
    public function failureReason(NotificationLog $log): string
    {
        if ($log->status === NotificationLog::STATUS_HELD) {
            return 'held';
        }

        $text = mb_strtolower((string) ($log->status_detail ?? $log->error ?? ''));
        foreach (['unknown_address', 'mailbox_full'] as $reason) {
            foreach ((array) config("swayy.mail_delivery.{$reason}_patterns", []) as $pattern) {
                if ($pattern !== '' && str_contains($text, mb_strtolower((string) $pattern))) {
                    return $reason;
                }
            }
        }

        return 'other';
    }
}

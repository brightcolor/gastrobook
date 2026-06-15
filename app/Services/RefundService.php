<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Support\Facades\DB;

/**
 * Flexible deposit refunds: off / manual (staff approval) / auto, each either
 * processed immediately or in a scheduled batch. Refund amount is a configurable
 * percentage of the paid deposit.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentProviderManager $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a refund request for a cancelled reservation according to the
     * location's refund policy. Returns null when refunds are off or there is
     * nothing refundable.
     */
    public function requestForReservation(Reservation $reservation, string $source = 'guest_cancel', ?User $actor = null): ?Refund
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return null;
        }

        $settings = $location->effectiveSettings();
        $mode = $settings->refund_mode;
        if ($mode === 'off') {
            return null;
        }

        $intent = PaymentIntent::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'paid')
            ->latest()
            ->first();
        if ($intent === null || empty($intent->metadata['refund_ref'])) {
            return null;
        }

        // Wrap duplicate-check + insert in a transaction with a pessimistic
        // lock so that two concurrent calls (e.g. guest double-clicks cancel,
        // or a webhook fires at the same time) cannot both pass the check and
        // each create a separate Refund row (= double refund to the guest).
        $refund = DB::transaction(function () use ($reservation, $intent, $settings, $mode, $source, $actor) {
            $existing = Refund::withoutGlobalScopes()
                ->where('reservation_id', $reservation->id)
                ->whereNotIn('status', ['rejected', 'failed'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $percent = max(0, min(100, (int) $settings->refund_percent));
            $amount = (int) round($intent->amount_minor * $percent / 100);
            if ($amount <= 0) {
                return null;
            }

            $refund = Refund::create([
                'tenant_id' => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $intent->id,
                'provider' => $intent->provider,
                'amount_minor' => $amount,
                'currency' => $intent->currency,
                'status' => $mode === 'auto' ? 'approved' : 'pending',
                'source' => $source,
                'reason' => 'cancellation',
                'requested_by' => $actor?->id,
            ]);

            $this->audit->log('refund.requested', $refund, null, [
                'amount_minor' => $amount, 'mode' => $mode,
            ], null, $actor, $reservation->tenant_id);

            return $refund;
        });

        if ($refund === null) {
            return null;
        }

        if ($refund->status === 'approved' && $settings->refund_processing === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    public function approve(Refund $refund, User $actor): Refund
    {
        if ($refund->status !== 'pending') {
            return $refund;
        }

        $refund->update(['status' => 'approved', 'approved_by' => $actor->id]);
        $this->audit->log('refund.approved', $refund, null, null, null, $actor, $refund->tenant_id);

        if ($this->processingMode($refund) === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    public function reject(Refund $refund, User $actor): Refund
    {
        if ($refund->status !== 'pending') {
            return $refund;
        }

        $refund->update(['status' => 'rejected', 'approved_by' => $actor->id]);
        $this->audit->log('refund.rejected', $refund, null, null, null, $actor, $refund->tenant_id);

        return $refund->fresh();
    }

    /**
     * Execute the refund against the payment provider. Idempotent.
     */
    public function process(Refund $refund): bool
    {
        if ($refund->status === 'completed') {
            return true;
        }
        if (! in_array($refund->status, ['approved', 'processing'], true)) {
            return false;
        }

        $refund->update(['status' => 'processing']);

        $tenant = Tenant::find($refund->tenant_id);
        $provider = $tenant ? $this->payments->provider($tenant, $refund->provider) : null;
        $intent = $refund->payment_intent_id ? PaymentIntent::withoutGlobalScopes()->find($refund->payment_intent_id) : null;
        $reference = $intent->metadata['refund_ref'] ?? null;

        if ($provider === null || ! $reference) {
            $refund->update(['status' => 'failed', 'error' => 'Kein Anbieter oder keine Zahlungsreferenz.']);

            return false;
        }

        $result = $provider->refund($reference, $refund->amount_minor, $refund->currency);
        if (! $result['ok']) {
            $refund->update(['status' => 'failed', 'error' => 'Anbieter hat die Rückerstattung abgelehnt.']);

            return false;
        }

        $fully = $intent === null || $refund->amount_minor >= $intent->amount_minor;

        $refund->update([
            'status' => 'completed',
            'provider_refund_id' => $result['id'],
            'processed_at' => now(),
        ]);
        $intent?->update(['status' => $fully ? 'refunded' : 'partially_refunded']);

        if ($refund->reservation_id) {
            Reservation::withoutGlobalScope('tenant')
                ->where('id', $refund->reservation_id)
                ->update(['payment_status' => $fully ? 'refunded' : 'partially_refunded']);
        }

        $this->audit->log('refund.completed', $refund, null, [
            'amount_minor' => $refund->amount_minor, 'provider_refund_id' => $result['id'],
        ], null, null, $refund->tenant_id);

        return true;
    }

    /**
     * Process all approved refunds that are due (scheduled batch).
     */
    public function processDue(): int
    {
        $count = 0;
        Refund::withoutGlobalScopes()
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->get()
            ->each(function (Refund $refund) use (&$count) {
                if ($this->process($refund)) {
                    $count++;
                }
            });

        return $count;
    }

    private function processingMode(Refund $refund): string
    {
        $reservation = $refund->reservation_id
            ? Reservation::withoutGlobalScope('tenant')->find($refund->reservation_id)
            : null;
        $location = $reservation?->location()->withoutGlobalScope('tenant')->first();

        return $location?->effectiveSettings()->refund_processing ?? 'immediate';
    }
}

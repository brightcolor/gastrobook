<?php

use App\Enums\ReservationStatus;
use App\Jobs\ProcessScheduledRefunds;
use App\Jobs\RunRetentionPolicies;
use App\Jobs\SendFeedbackRequests;
use App\Jobs\SendReservationReminders;
use App\Jobs\SendTrialExpiryWarnings;
use App\Models\DepositRule;
use App\Models\FeedbackRequest;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SendReservationReminders)->everyFifteenMinutes();
Schedule::job(new SendFeedbackRequests)->hourly();
Schedule::job(new ProcessScheduledRefunds)->everyFifteenMinutes();
Schedule::call(fn () => app(WaitlistService::class)->expireStale())->everyTenMinutes();
Schedule::job(new RunRetentionPolicies)->dailyAt('03:30');
Schedule::call(fn () => FeedbackRequest::pruneUnanswered())->dailyAt('03:45');
Schedule::job(new SendTrialExpiryWarnings)->dailyAt('08:00');

// Expire unpaid reservations past their payment deadline.
// A rule may opt out of this ("kein Auto-Storno") – those bookings stay open
// so the business can chase the payment or decide by hand.
Schedule::call(function () {
    Reservation::withoutGlobalScopes()
        ->where('status', ReservationStatus::PaymentPending->value)
        ->whereNotNull('payment_due_at')
        ->where('payment_due_at', '<', now())
        // withoutGlobalScopes: the scheduler runs without a tenant context, so
        // the tenant scope on DepositRule would match nothing at all here.
        ->where(function ($q) {
            $q->whereNull('deposit_rule_id')
                ->orWhereIn('deposit_rule_id', DepositRule::withoutGlobalScopes()
                    ->where('cancel_unpaid_automatically', true)
                    ->select('id'));
        })
        ->each(function ($reservation) {
            app(ReservationLifecycleService::class)->transition(
                $reservation,
                ReservationStatus::Expired,
                null,
                'system',
                'payment_deadline_exceeded'
            );
        });
})->everyTenMinutes();

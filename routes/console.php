<?php

use App\Enums\ReservationStatus;
use App\Jobs\ProcessScheduledRefunds;
use App\Jobs\RunRetentionPolicies;
use App\Jobs\SendFeedbackRequests;
use App\Jobs\SendReservationReminders;
use App\Jobs\SendTrialExpiryWarnings;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SendReservationReminders)->everyFifteenMinutes();
Schedule::job(new SendFeedbackRequests)->hourly();
Schedule::job(new ProcessScheduledRefunds)->everyFifteenMinutes();
Schedule::call(fn () => app(WaitlistService::class)->expireStale())->everyTenMinutes();
Schedule::job(new RunRetentionPolicies)->dailyAt('03:30');
Schedule::job(new SendTrialExpiryWarnings)->dailyAt('08:00');

// Expire unpaid reservations past their payment deadline
Schedule::call(function () {
    Reservation::withoutGlobalScopes()
        ->where('status', ReservationStatus::PaymentPending->value)
        ->whereNotNull('payment_due_at')
        ->where('payment_due_at', '<', now())
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

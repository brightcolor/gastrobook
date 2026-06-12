<?php

use App\Jobs\RunRetentionPolicies;
use App\Jobs\SendFeedbackRequests;
use App\Jobs\SendReservationReminders;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SendReservationReminders)->everyFifteenMinutes();
Schedule::job(new SendFeedbackRequests)->hourly();
Schedule::call(fn () => app(WaitlistService::class)->expireStale())->everyTenMinutes();
Schedule::job(new RunRetentionPolicies)->dailyAt('03:30');

// Expire unpaid reservations past their payment deadline
Schedule::call(function () {
    \App\Models\Reservation::withoutGlobalScopes()
        ->where('status', \App\Enums\ReservationStatus::PaymentPending->value)
        ->whereNotNull('payment_due_at')
        ->where('payment_due_at', '<', now())
        ->each(function ($reservation) {
            app(\App\Services\ReservationLifecycleService::class)->transition(
                $reservation,
                \App\Enums\ReservationStatus::Expired,
                null,
                'system',
                'payment_deadline_exceeded'
            );
        });
})->everyTenMinutes();

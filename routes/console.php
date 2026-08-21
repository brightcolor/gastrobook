<?php

use App\Jobs\ExpireUnconfirmedReservations;
use App\Jobs\ExpireUnpaidReservations;
use App\Jobs\ProcessScheduledRefunds;
use App\Jobs\RunRetentionPolicies;
use App\Jobs\SendFeedbackRequests;
use App\Jobs\SendMarketingCampaigns;
use App\Jobs\SendReservationReminders;
use App\Jobs\SendTrialExpiryWarnings;
use App\Models\FeedbackRequest;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SendReservationReminders)->everyFifteenMinutes();
Schedule::job(new SendFeedbackRequests)->hourly();
Schedule::job(new ProcessScheduledRefunds)->everyFifteenMinutes();
Schedule::call(fn () => app(WaitlistService::class)->expireStale())->everyTenMinutes();
Schedule::job(new RunRetentionPolicies)->dailyAt('03:30');
Schedule::call(fn () => FeedbackRequest::pruneUnanswered())->dailyAt('03:45');
Schedule::job(new SendTrialExpiryWarnings)->dailyAt('08:00');
// Guest campaigns: mid-morning, so a birthday greeting does not arrive at 03:00.
Schedule::job(new SendMarketingCampaigns)->dailyAt('09:00');

// Offene Anzahlungen: erinnern, wenn die halbe Frist um ist, und nach Ablauf
// den Tisch freigeben. Eine Regel kann das Auto-Storno abschalten – solche
// Buchungen bleiben stehen, damit der Betrieb selbst entscheiden kann.
// Alle fuenf Minuten, weil die Frist frei einstellbar ist und bei kurzen
// Fristen sonst die Erinnerung erst nach dem Ablauf käme.
Schedule::job(new ExpireUnpaidReservations)->everyFiveMinutes();

// Buchungen, deren E-Mail-Bestätigung nie kam. Der Link gilt 24 Stunden;
// stündlich prüfen reicht, danach wird der Tisch wieder frei.
Schedule::job(new ExpireUnconfirmedReservations)->hourly();

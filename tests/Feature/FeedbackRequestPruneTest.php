<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\FeedbackRequest;
use App\Models\FeedbackResponse;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FeedbackRequestPruneTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_prunes_only_old_unanswered_requests(): void
    {
        $setup = $this->createTenantSetup();
        $t = $setup['tenant']->id;
        $l = $setup['location']->id;

        $reservationId = function () use ($setup, $t, $l) {
            $start = CarbonImmutable::now($setup['location']->timezone)->subMonths(8)->setTime(19, 0);

            return Reservation::create([
                'tenant_id' => $t, 'location_id' => $l, 'party_size' => 2,
                'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
                'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Completed, 'source' => 'online',
                'guest_name_snapshot' => 'Gast', 'code' => 'R-'.uniqid(), 'manage_token' => str_repeat('x', 48),
            ])->id;
        };

        $aged = fn (FeedbackRequest $fr, $when) => tap($fr, fn ($m) => $m->forceFill(['created_at' => $when])->save());

        // old + unanswered → pruned
        $oldUnanswered = $aged(FeedbackRequest::withoutGlobalScopes()->create([
            'tenant_id' => $t, 'location_id' => $l, 'reservation_id' => $reservationId(),
        ]), now()->subMonths(8));
        // old + answered → kept (its response holds the valuable data)
        $oldAnswered = $aged(FeedbackRequest::withoutGlobalScopes()->create([
            'tenant_id' => $t, 'location_id' => $l, 'reservation_id' => $reservationId(), 'responded_at' => now()->subMonths(7),
        ]), now()->subMonths(8));
        FeedbackResponse::withoutGlobalScopes()->create([
            'tenant_id' => $t, 'location_id' => $l, 'feedback_request_id' => $oldAnswered->id, 'score' => 5,
        ]);
        // recent + unanswered → kept (still within its useful window)
        $recentUnanswered = $aged(FeedbackRequest::withoutGlobalScopes()->create([
            'tenant_id' => $t, 'location_id' => $l, 'reservation_id' => $reservationId(),
        ]), now()->subDays(3));

        $deleted = FeedbackRequest::pruneUnanswered();

        $this->assertSame(1, $deleted);
        $this->assertNull(FeedbackRequest::withoutGlobalScopes()->find($oldUnanswered->id));
        $this->assertNotNull(FeedbackRequest::withoutGlobalScopes()->find($oldAnswered->id));
        $this->assertNotNull(FeedbackRequest::withoutGlobalScopes()->find($recentUnanswered->id));
        // the answered request's response is untouched
        $this->assertDatabaseHas('feedback_responses', ['feedback_request_id' => $oldAnswered->id]);
    }
}

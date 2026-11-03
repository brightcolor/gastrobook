<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Trifft eine Buchung im Adminbereich auf eine Sperrzeit, sagt die Meldung dem
 * Team, welche Sperrzeit es ist: Raum oder ganzer Betrieb, Zeitraum und Grund.
 * Vorher bekam das Team die Gast-Meldung "wir sind geschlossen, bitte einen
 * anderen Tag" - auch wenn nur ein einzelner Raum gesperrt war und die übrigen
 * Räume frei waren.
 */
class BlackoutMessageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @var array{tenant: Tenant, location: Location, room: Room, tables: array<RestaurantTable>} */
    private array $setup;

    private User $staff;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setup = $this->createTenantSetup();
        $this->setup['room']->update(['name' => 'Wintergarten']);
        $this->staff = $this->createMember($this->setup['tenant']);
        $this->tz = $this->setup['location']->timezone;
    }

    private function blackout(CarbonImmutable $fromLocal, CarbonImmutable $untilLocal, ?int $roomId, ?string $reason): void
    {
        BlackoutPeriod::create([
            'tenant_id' => $this->setup['tenant']->id,
            'location_id' => $this->setup['location']->id,
            'room_id' => $roomId,
            'reduce_covers_to' => null,
            'starts_at' => $fromLocal->utc(),
            'ends_at' => $untilLocal->utc(),
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function createMessage(CarbonImmutable $startLocal, ?User $actor, array $extra = []): string
    {
        try {
            app(ReservationLifecycleService::class)->create($this->setup['location'], $extra + [
                'party_size' => 2,
                'start_local' => $startLocal,
                'duration_minutes' => 180,
                'source' => 'manual',
                'guest_name' => 'Testgast',
            ], $actor);
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        $this->fail('Die Buchung hätte an der Sperrzeit scheitern müssen.');
    }

    public function test_staff_sees_room_period_and_reason_of_a_room_blackout(): void
    {
        $day = CarbonImmutable::now($this->tz)->addDays(3);
        $this->blackout($day->setTime(18, 30), $day->setTime(22, 0), $this->setup['room']->id, 'Geschlossene Gesellschaft');
        $this->clearTenantContext();

        $message = $this->createMessage($day->setTime(19, 0), $this->staff, [
            'table_ids' => [$this->setup['tables'][0]->id],
        ]);

        $this->assertStringContainsString('Raum „Wintergarten“', $message);
        $this->assertStringContainsString('am '.$day->format('d.m.Y').' von 18:30 bis 22:00 Uhr', $message);
        $this->assertStringContainsString('Grund: Geschlossene Gesellschaft', $message);
        $this->assertStringContainsString('anderen Raum', $message);
        $this->assertStringNotContainsString('wir', $message);
    }

    public function test_staff_sees_a_location_wide_blackout_over_several_days(): void
    {
        $first = CarbonImmutable::now($this->tz)->addDays(3)->startOfDay();
        $last = $first->addDays(2);
        $this->blackout($first, $last, null, 'Betriebsferien');
        $this->clearTenantContext();

        $message = $this->createMessage($first->addDay()->setTime(19, 0), $this->staff);

        $this->assertStringContainsString('ganze Betrieb', $message);
        $this->assertStringContainsString('vom '.$first->format('d.m.Y').', 00:00 Uhr bis '.$last->format('d.m.Y').', 00:00 Uhr', $message);
        $this->assertStringContainsString('Grund: Betriebsferien', $message);
        $this->assertStringNotContainsString('anderen Raum', $message);
    }

    public function test_message_without_reason_names_no_reason(): void
    {
        $day = CarbonImmutable::now($this->tz)->addDays(3);
        $this->blackout($day->setTime(18, 0), $day->setTime(23, 0), null, null);
        $this->clearTenantContext();

        $message = $this->createMessage($day->setTime(19, 0), $this->staff);

        $this->assertStringContainsString('gesperrt', $message);
        $this->assertStringNotContainsString('Grund:', $message);
    }

    /** Die API hat keinen angemeldeten Mitarbeiter und behält die Gast-Meldung. */
    public function test_request_without_staff_keeps_the_guest_wording_and_hides_the_reason(): void
    {
        $day = CarbonImmutable::now($this->tz)->addDays(3);
        $this->blackout($day->setTime(18, 0), $day->setTime(23, 0), null, 'Interne Feier');
        $this->clearTenantContext();

        $message = $this->createMessage($day->setTime(19, 0), null, ['source' => 'api']);

        $this->assertStringContainsString('geschlossen', $message);
        $this->assertStringNotContainsString('Interne Feier', $message);
    }

    public function test_online_booking_keeps_the_guest_wording_even_with_a_user(): void
    {
        $day = CarbonImmutable::now($this->tz)->addDays(3);
        $this->blackout($day->setTime(18, 0), $day->setTime(23, 0), null, 'Interne Feier');
        $this->clearTenantContext();

        $message = $this->createMessage($day->setTime(19, 0), $this->staff, ['source' => 'online']);

        $this->assertStringNotContainsString('Interne Feier', $message);
    }

    public function test_staff_reschedule_into_a_blackout_names_it(): void
    {
        $day = CarbonImmutable::now($this->tz)->addDays(3);
        $reservation = app(ReservationLifecycleService::class)->create($this->setup['location'], [
            'party_size' => 2,
            'start_local' => $day->setTime(13, 0),
            'source' => 'manual',
            'guest_name' => 'Testgast',
            'table_ids' => [$this->setup['tables'][0]->id],
        ], $this->staff);
        $this->blackout($day->setTime(18, 30), $day->setTime(22, 0), null, 'Geschlossene Gesellschaft');
        $this->clearTenantContext();

        try {
            app(ReservationLifecycleService::class)->reschedule(
                Reservation::withoutGlobalScopes()->findOrFail($reservation->id),
                $day->setTime(19, 0),
                null,
                $this->staff,
                'user',
            );
            $this->fail('Die Umbuchung hätte an der Sperrzeit scheitern müssen.');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();
        }

        $this->assertStringContainsString('ganze Betrieb', $message);
        $this->assertStringContainsString('Grund: Geschlossene Gesellschaft', $message);
    }
}

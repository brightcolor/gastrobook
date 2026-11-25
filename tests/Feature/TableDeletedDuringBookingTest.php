<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein Tisch wird gelöscht, während eine Buchung für ihn läuft: Die Buchung liest die Tische vor dem Schreiben noch
 * einmal unter einer Sperre und sagt dem Gast, dass der Tisch nicht mehr zur Verfügung steht. Umgekehrt lehnt das
 * Löschen einen Tisch mit kommender Reservierung ab.
 */
class TableDeletedDuringBookingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(array $setup, array $tableIds, array $extra = []): array
    {
        return array_merge([
            'party_size' => 2,
            'start_local' => CarbonImmutable::now($setup['location']->timezone)->addDays(2)->setTime(19, 0),
            'source' => 'online',
            'guest_name' => 'Gast Beispiel',
            'guest_email' => 'gast@example.test',
            'table_ids' => $tableIds,
            'table_chosen_by_guest' => true,
        ], $extra);
    }

    private function messageFor(callable $book): string
    {
        try {
            $book();
        } catch (ValidationException $e) {
            return $e->errors()['table_ids'][0] ?? '';
        }
        $this->fail('Die Buchung ist mit einem gelöschten Tisch durchgegangen.');
    }

    public function test_a_guest_booking_on_a_table_deleted_in_the_meantime_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][1];
        // Der Controller hat den Tisch gelesen, dann hat das Team ihn gelöscht, danach kommt die Buchung an.
        $table->delete();

        $message = $this->messageFor(fn () => app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($setup, [$table->id])));

        $this->assertSame('Der gewählte Tisch steht leider nicht mehr zur Verfügung. Bitte wählen Sie einen anderen Tisch oder eine andere Uhrzeit.', $message);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('reservation_tables')->count());
    }

    public function test_the_message_follows_the_informal_address(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['guest_address' => 'du']);
        $setup['location']->unsetRelation('settings');
        $table = $setup['tables'][1];
        $table->delete();

        $message = $this->messageFor(fn () => app(ReservationLifecycleService::class)->create($setup['location']->fresh(), $this->booking($setup, [$table->id])));

        $this->assertSame('Der gewählte Tisch steht leider nicht mehr zur Verfügung. Bitte wähle einen anderen Tisch oder eine andere Uhrzeit.', $message);
    }

    public function test_an_automatically_assigned_table_deleted_in_the_meantime_gives_the_no_table_message(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][1];
        $table->delete();

        $message = $this->messageFor(fn () => app(ReservationLifecycleService::class)->create(
            $setup['location'],
            $this->booking($setup, [$table->id], ['table_chosen_by_guest' => false]),
        ));

        $this->assertSame(app(ReservationLifecycleService::class)->availabilityMessage('no_table'), $message);
    }

    public function test_the_team_gets_its_own_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant']);
        $table = $setup['tables'][1];
        $table->delete();

        $message = $this->messageFor(fn () => app(ReservationLifecycleService::class)->create(
            $setup['location'],
            $this->booking($setup, [$table->id], ['source' => 'manual']),
            $owner,
        ));

        $this->assertSame('Mindestens ein gewählter Tisch wurde gerade gelöscht. Bitte die Tische neu wählen.', $message);
    }

    public function test_a_guest_whose_table_was_taken_meanwhile_is_told_so(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][1];
        $service = app(ReservationLifecycleService::class);
        $service->create($setup['location'], $this->booking($setup, [$table->id]));

        $message = $this->messageFor(fn () => $service->create($setup['location'], $this->booking($setup, [$table->id], ['guest_email' => 'zweiter@example.test'])));

        $this->assertSame('Der gewählte Tisch ist zu dieser Zeit leider nicht mehr frei. Bitte wählen Sie einen anderen Tisch oder eine andere Uhrzeit.', $message);
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_a_booking_on_an_existing_table_still_goes_through(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][1];

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($setup, [$table->id]));

        $this->assertSame([$table->id], $reservation->tables()->pluck('restaurant_tables.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_deleting_a_table_with_an_upcoming_reservation_is_refused(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant']);
        $table = $setup['tables'][1];
        app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($setup, [$table->id]));

        $this->actingAs($owner)
            ->deleteJson(route('admin.settings.tables.delete', $table))
            ->assertStatus(422)
            ->assertJsonFragment(['„T2“ hat noch eine kommende oder laufende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.']);

        $this->assertNotNull($table->fresh());
    }
}

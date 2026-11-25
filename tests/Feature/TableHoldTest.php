<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PruneTableHolds;
use App\Models\Reservation;
use App\Models\TableHold;
use App\Services\ReservationLifecycleService;
use App\Services\TableHolds;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tisch beim Buchen halten: Wählt ein Gast eine Uhrzeit, hält Swayy den Tisch für ihn, bis die Haltezeit abläuft.
 * Andere Gäste sehen ihn so lange als belegt; das Team bucht unberührt. Vorgabe aus.
 */
class TableHoldTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->day = CarbonImmutable::now('Europe/Berlin')->addDays(2)->toDateString();
    }

    /**
     * Ein Restaurant mit genau einem Tisch für 1–4 Personen.
     *
     * @return array<string, mixed>
     */
    private function restaurant(array $settings = ['table_hold_enabled' => true, 'table_hold_minutes' => 3]): array
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['location']->settings->update($settings);
        $setup['url'] = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
        $this->clearTenantContext();

        return $setup;
    }

    private function holdAt(array $setup, string $time = '19:00', array $extra = []): TestResponse
    {
        return $this->postJson($setup['url'].'/hold', array_merge(['date' => $this->day, 'time' => $time, 'party_size' => 2], $extra));
    }

    /**
     * @return array<int, string>
     */
    private function slots(array $setup, ?string $token = null): array
    {
        return $this->getJson($setup['url'].'/slots?date='.$this->day.'&party_size=2'.($token ? '&hold='.$token : ''))
            ->assertOk()
            ->json('slots');
    }

    private function book(array $setup, string $name, ?string $token = null, string $time = '19:00'): TestResponse
    {
        return $this->from($setup['url'])->post($setup['url'], [
            'date' => $this->day, 'time' => $time, 'party_size' => 2,
            'name' => $name, 'email' => strtolower($name).'@example.test', 'phone' => '+49 170 0000000', 'privacy_accepted' => '1',
            'hold_token' => $token,
        ]);
    }

    public function test_the_setting_is_off_by_default_and_nothing_is_held(): void
    {
        $setup = $this->restaurant([]);

        $this->assertFalse((bool) $setup['location']->fresh()->effectiveSettings()->table_hold_enabled);
        $this->holdAt($setup)->assertOk()->assertExactJson(['held' => false]);
        $this->assertSame(0, TableHold::count());
        $this->assertContains('19:00', $this->slots($setup));
    }

    public function test_a_held_table_is_taken_for_others_and_free_for_the_holder(): void
    {
        $setup = $this->restaurant();
        $this->travelTo(CarbonImmutable::parse($this->day.' 10:00', 'Europe/Berlin')->subDay());

        $response = $this->holdAt($setup)->assertOk()->assertJsonPath('held', true)->assertJsonPath('until', '10:03');
        $token = $response->json('hold');
        $this->assertSame(180, $response->json('seconds'));

        $this->assertNotContains('19:00', $this->slots($setup));
        $this->assertContains('19:00', $this->slots($setup, $token));
        // Die Zeit davor bleibt frei, solange sie den gehaltenen Zeitraum nicht berührt.
        $this->assertContains('12:00', $this->slots($setup));
    }

    public function test_another_guest_is_told_the_table_is_no_longer_free_and_the_holder_books(): void
    {
        $setup = $this->restaurant();
        $token = $this->holdAt($setup)->json('hold');

        $this->book($setup, 'Zweiter')
            ->assertRedirect($setup['url'])
            ->assertSessionHasErrors('start_at');
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        $this->book($setup, 'Erster', $token)->assertRedirect();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
        // Gebucht: Das Halten ist erfüllt und verschwindet.
        $this->assertSame(0, TableHold::count());
    }

    public function test_after_the_hold_time_others_can_book_again(): void
    {
        $setup = $this->restaurant(['table_hold_enabled' => true, 'table_hold_minutes' => 5]);
        $this->holdAt($setup);

        $this->travel(4)->minutes();
        $this->assertNotContains('19:00', $this->slots($setup));

        $this->travel(2)->minutes();
        $this->assertContains('19:00', $this->slots($setup));
        $this->book($setup, 'Zweiter')->assertRedirect();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_holder_still_books_after_the_hold_ran_out_while_the_table_is_free(): void
    {
        $setup = $this->restaurant();
        $token = $this->holdAt($setup)->json('hold');
        $this->travel(10)->minutes();

        $this->book($setup, 'Erster', $token)->assertRedirect();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_team_books_regardless_of_a_hold(): void
    {
        $setup = $this->restaurant();
        $this->holdAt($setup);

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => CarbonImmutable::parse($this->day.' 19:00', 'Europe/Berlin'),
            'source' => 'phone',
            'guest_name' => 'Telefon',
        ], $this->createMember($setup['tenant']));

        $this->assertSame([$setup['tables'][0]->id], $reservation->tables()->pluck('restaurant_tables.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_choosing_another_time_replaces_the_hold(): void
    {
        $setup = $this->restaurant();
        $token = $this->holdAt($setup)->json('hold');
        $this->assertSame($token, $this->holdAt($setup, '13:00', ['hold' => $token])->json('hold'));

        $this->assertSame(1, TableHold::count());
        $this->assertContains('19:00', $this->slots($setup));
        $this->assertNotContains('13:00', $this->slots($setup));
    }

    public function test_a_second_guest_gets_no_hold_on_a_held_table(): void
    {
        $setup = $this->restaurant();
        $this->holdAt($setup);

        $this->holdAt($setup)->assertOk()->assertExactJson(['held' => false]);
        $this->assertSame(1, TableHold::count());
    }

    public function test_the_floor_plan_shows_a_held_table_as_taken_for_others_only(): void
    {
        $setup = $this->restaurant(['table_hold_enabled' => true, 'public_floorplan_enabled' => true]);
        $table = $setup['tables'][0];
        $token = $this->holdAt($setup, '19:00', ['table_id' => $table->id])->assertJsonPath('held', true)->json('hold');

        $status = fn (?string $hold) => collect($this->getJson($setup['url'].'/floorplan?date='.$this->day.'&time=19:00&party_size=2'.($hold ? '&hold='.$hold : ''))
            ->assertOk()->json('rooms'))->flatMap(fn ($room) => $room['tables'])->firstWhere('id', $table->id)['status'];

        $this->assertSame('occupied', $status(null));
        $this->assertSame('available', $status($token));
    }

    public function test_counting_by_persons_holds_nothing(): void
    {
        $setup = $this->restaurant(['table_hold_enabled' => true, 'capacity_mode' => 'person', 'max_covers_per_slot' => 20]);

        $this->holdAt($setup)->assertOk()->assertExactJson(['held' => false]);
    }

    public function test_hold_minutes_come_from_the_settings_and_their_limits(): void
    {
        $setup = $this->restaurant(['table_hold_enabled' => true, 'table_hold_minutes' => null]);
        $settings = fn () => $setup['location']->fresh()->effectiveSettings();

        $this->assertSame(3, $settings()->tableHoldMinutes());

        config(['swayy.booking.hold_default_minutes' => 7, 'swayy.booking.hold_max_minutes' => 10]);
        $this->assertSame(7, $settings()->tableHoldMinutes());

        $setup['location']->settings->update(['table_hold_minutes' => 30]);
        $this->assertSame(10, $settings()->tableHoldMinutes());

        $setup['location']->settings->update(['table_hold_enabled' => false]);
        $this->assertNull($settings()->tableHoldMinutes());
    }

    public function test_the_capacity_page_saves_and_checks_the_setting(): void
    {
        $setup = $this->restaurant([]);
        $owner = $this->createMember($setup['tenant']);
        $payload = [
            'slot_interval_minutes' => 30, 'default_duration_minutes' => 120, 'buffer_minutes' => 0,
            'capacity_mode' => 'table', 'table_hold_enabled' => '1', 'table_hold_minutes' => 4,
        ];

        $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk()->assertSee('Tisch beim Buchen halten');
        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)->assertOk();
        $saved = $setup['location']->fresh()->effectiveSettings();
        $this->assertTrue($saved->table_hold_enabled);
        $this->assertSame(4, $saved->tableHoldMinutes());

        config(['swayy.booking.hold_max_minutes' => 5]);
        $this->actingAs($owner)->putJson('/admin/settings/capacity', ['table_hold_minutes' => 6] + $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.table_hold_minutes.0', 'Die Haltezeit beträgt höchstens 5 Minuten. Bitte einen kleineren Wert eintragen.');
        $this->actingAs($owner)->putJson('/admin/settings/capacity', ['table_hold_minutes' => null] + $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.table_hold_minutes.0', 'Bitte bei „Haltezeit“ eintragen, wie viele Minuten der Tisch gehalten wird, oder das Halten ausschalten.');

        // Ausschalten behält die eingetragene Zahl.
        $this->actingAs($owner)->putJson('/admin/settings/capacity', ['table_hold_enabled' => '0', 'table_hold_minutes' => null] + $payload)->assertOk();
        $saved = $setup['location']->fresh()->effectiveSettings();
        $this->assertFalse($saved->table_hold_enabled);
        $this->assertSame(4, (int) $saved->table_hold_minutes);
    }

    public function test_expired_holds_are_pruned(): void
    {
        $setup = $this->restaurant();
        $this->holdAt($setup);
        $this->travel(5)->minutes();

        app()->call([new PruneTableHolds, 'handle']);

        $this->assertSame(0, TableHold::count());
    }

    public function test_the_booking_page_asks_for_a_hold_only_when_switched_on(): void
    {
        $on = $this->restaurant();
        $this->get($on['url'])->assertOk()
            ->assertSee('id="holdNotice"', false)
            ->assertSee('Wir halten den Tisch bis :until Uhr', false);

        $off = $this->restaurant([]);
        $this->get($off['url'])->assertOk()->assertDontSee('id="holdNotice"', false);
    }

    public function test_a_forged_token_is_ignored(): void
    {
        $setup = $this->restaurant();
        $this->holdAt($setup);

        $this->assertFalse(app(TableHolds::class)->validToken('kurz'));
        $this->assertNotContains('19:00', $this->slots($setup, 'kurz'));
    }
}

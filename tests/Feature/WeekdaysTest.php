<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\Weekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Wochentagsnamen stehen an einer Stelle (App\Support\Weekdays), und
 * table_blocks hat einen Index für die Abfrage nach Standort und Ende.
 *
 * Vorher standen die Namen in sechs Dateien, auf der Bestätigungsseite mit
 * Sonntag als Tag 0, überall sonst mit Montag.
 */
class WeekdaysTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_week_starts_on_monday_like_the_weekday_columns(): void
    {
        $this->assertSame(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'], Weekdays::NAMES);
        $this->assertSame(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'], Weekdays::SHORT);
        $this->assertSame('Mittwoch', Weekdays::name(2));
        $this->assertSame('So', Weekdays::short(6));
        // Ein Datum nennt seinen Wochentag, unabhängig davon, wie Carbon die Tage zählt.
        $this->assertSame('Sonntag', Weekdays::of(CarbonImmutable::parse('2026-11-29')));
        $this->assertSame('Freitag', Weekdays::of(CarbonImmutable::parse('2026-11-27')));
    }

    public function test_no_other_file_keeps_its_own_list_of_weekday_names(): void
    {
        $own = realpath(app_path('Support/Weekdays.php'));
        $lists = '/[\'"](Montag|Sonntag)[\'"]\s*,\s*[\'"](Dienstag|Montag)[\'"]|[\'"]Mo[\'"]\s*,\s*[\'"]Di[\'"]/';
        $found = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views')), ...File::allFiles(resource_path('js'))])
            ->filter(fn ($file) => $file->getRealPath() !== $own)
            ->filter(fn ($file) => preg_match($lists, (string) file_get_contents($file->getRealPath())) === 1)
            ->map(fn ($file) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath()))
            ->values()
            ->all();

        $this->assertSame([], $found, 'Diese Dateien führen eigene Wochentagsnamen; bitte App\Support\Weekdays nutzen.');
    }

    public function test_the_confirmation_names_the_right_day(): void
    {
        $setup = $this->createTenantSetup();
        // Sonntag, 29.11.2026: Mit der alten Liste ab Sonntag stimmte der Tag, mit einer Liste ab Montag wäre er falsch.
        $start = CarbonImmutable::parse('2026-11-29 19:00', 'Europe/Berlin');
        $this->travelTo($start->subDays(2));
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(), 'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed, 'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $this->get('/reservation/'.$reservation->code.'/manage/'.$reservation->manage_token)->assertOk();
        $this->get(route('booking.confirmation', [$reservation->code, $reservation->manage_token]))
            ->assertOk()
            ->assertSee('Sonntag, 29. November');
    }

    public function test_table_blocks_have_an_index_on_location_and_end(): void
    {
        $this->assertTrue(Schema::hasIndex('table_blocks', 'table_blocks_location_end_idx'));
        $this->assertTrue(Schema::hasIndex('table_blocks', ['location_id', 'ends_at']));
    }
}

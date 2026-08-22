<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Im Reservierungsbuch stand nur die Uhrzeit. Bei einem Zeitraum ueber mehrere
 * Tage - und „Alle" ist die Voreinstellung - liessen sich die Zeilen nicht mehr
 * auseinanderhalten: 15:00 konnte heute oder in drei Wochen sein.
 */
class ReservationBookColumnsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function makeOn(array $setup, CarbonImmutable $tag, string $name): Reservation
    {
        $start = $tag->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 6,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationLabel(array $setup, CarbonImmutable $tag): string
    {
        return Reservation::withoutGlobalScopes()
            ->where('location_id', $setup['location']->id)
            ->whereDate('reservation_date', $tag->toDateString())
            ->firstOrFail()
            ->localDayLabel();
    }

    public function test_the_book_names_the_day_of_every_booking(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $heute = CarbonImmutable::now($tz);

        $this->makeOn($setup, $heute, 'GastHeute');
        $this->makeOn($setup, $heute->addDay(), 'GastMorgen');
        $spaeter = $heute->addDays(9);
        $this->makeOn($setup, $spaeter, 'GastSpaeter');
        $this->clearTenantContext();

        $inhalt = (string) $this->actingAs($admin)->get('/admin/reservations?range=all')->assertOk()->getContent();

        // Weder „Heute" noch „Morgen" taugen als Nachweis: Beide stehen auch
        // ausserhalb der Tabelle – „Heute" auf dem Zeitraum-Filterknopf,
        // „Morgen" im Erklaertext der Spaltenueberschrift. Ein assertSee darauf
        // waere auch dann gruen, wenn die ganze Spalte fehlte.
        // Geprueft wird deshalb im Tabellenkoerper.
        $koerper = mb_substr($inhalt, (int) mb_strpos($inhalt, '<tbody'));

        $this->assertStringContainsString('Heute', $koerper);
        $this->assertStringContainsString('Morgen', $koerper);
        $this->assertStringContainsString($spaeter->translatedFormat('D, d.m.'), $koerper);

        // Und die Beschriftung selbst, unabhaengig vom Rendern.
        $this->assertSame('Heute', $this->reservationLabel($setup, $heute));
        $this->assertSame('Morgen', $this->reservationLabel($setup, $heute->addDay()));
        $this->assertSame($spaeter->translatedFormat('D, d.m.'), $this->reservationLabel($setup, $spaeter));
    }

    public function test_the_book_shows_when_each_booking_came_in(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;

        $reservation = $this->makeOn($setup, CarbonImmutable::now($tz)->addDays(4), 'GastMitEingang');
        // Vor zwei Wochen gebucht - der Unterschied zum Besuchstag ist der Punkt.
        $eingang = CarbonImmutable::now($tz)->subDays(14)->setTime(9, 42);
        $reservation->forceFill(['created_at' => $eingang->utc()])->saveQuietly();
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations?range=all')
            ->assertOk()
            ->assertSee($eingang->format('d.m.y'))
            ->assertSee($eingang->format('H:i'));
    }

    public function test_the_party_size_column_is_labelled(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->makeOn($setup, CarbonImmutable::now($setup['location']->timezone), 'GastSechs');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations?range=all')
            ->assertOk()
            ->assertSee('Pers.')
            ->assertSee('Gebucht');
    }

    public function test_the_detail_page_shows_the_weekday_and_the_booking_time(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;

        $tag = CarbonImmutable::now($tz)->addDays(5);
        $reservation = $this->makeOn($setup, $tag, 'GastDetail');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('Gebucht am')
            ->assertSee($tag->setTime(19, 0)->translatedFormat('D, d.m.Y'));
    }

    public function test_the_export_carries_weekday_and_booking_time(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;

        $tag = CarbonImmutable::now($tz)->addDays(2);
        $this->makeOn($setup, $tag, 'GastExport');
        $this->clearTenantContext();

        $antwort = $this->actingAs($admin)->get('/admin/reservations/export?from='.$tag->subDay()->toDateString().'&until='.$tag->addDays(2)->toDateString());
        $antwort->assertOk();

        $csv = $antwort->streamedContent();

        $this->assertStringContainsString('Wochentag', $csv);
        $this->assertStringContainsString('Gebucht am', $csv);
        $this->assertStringContainsString('GastExport', $csv);
    }
}

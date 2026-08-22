<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Jede Gästeseite muss sich rendern lassen.
 *
 * Die Verwaltungsseite lief über mehrere Versionen hinweg in einen
 * Serverfehler, ohne dass ein Test das gemerkt hätte: Die vorhandenen Tests
 * prüften nur die Weiterleitung DORTHIN, nie die Seite selbst.
 *
 * Die Ursache war ausserdem eine, die kein Blick auf den Ausschnitt findet -
 * die Kurzform des PHP-Blocks mit Klammern zieht einen nachfolgenden Block an
 * sich und verschluckt alles dazwischen. Der zweite Test hier sucht diese
 * Kombination in allen Ansichten, nicht nur in dieser einen.
 */
class PublicPagesRenderTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_management_page_renders(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $this->get(route('booking.manage', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))
            ->assertOk()
            ->assertSee($reservation->code)
            ->assertSee('stornieren', false);
    }

    public function test_the_confirmation_page_renders(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $antwort = $this->get(route('booking.confirmation', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))->assertOk();

        // Kein fremdes CDN mehr auf der Seite, auf der Buchungscode und
        // Verwaltungstoken im Link stehen.
        $antwort->assertDontSee('cdn.jsdelivr.net');
    }

    public function test_the_booking_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()
            ->assertSee($setup['location']->name);
    }

    public function test_the_reschedule_page_renders(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $this->get(route('booking.reschedule', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))->assertOk();
    }

    /**
     * Vollständigkeitsprüfung statt Einzelfall: Keine Ansicht darf die
     * Klammer-Kurzform des PHP-Blocks VOR einem echten Block benutzen.
     */
    public function test_no_view_mixes_the_short_php_form_before_a_block(): void
    {
        $verdaechtig = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }

            $zeilen = file($datei->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            $kurz = null;
            $block = null;

            foreach ($zeilen as $nr => $zeile) {
                if ($kurz === null && preg_match('/@php\s*\(/', $zeile)) {
                    $kurz = $nr + 1;
                }
                if ($block === null && preg_match('/^\s*@php\s*$/', $zeile)) {
                    $block = $nr + 1;
                }
            }

            if ($kurz !== null && $block !== null && $kurz < $block) {
                $verdaechtig[] = $datei->getRelativePathname().' (Zeile '.$kurz.' vor '.$block.')';
            }
        }

        $this->assertSame([], $verdaechtig, 'Diese Ansichten laufen in einen Serverfehler.');
    }
}

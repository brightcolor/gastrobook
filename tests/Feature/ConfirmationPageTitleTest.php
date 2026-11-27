<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Der Browsertitel der Bestätigungsseite nennt denselben Stand wie die Seite.
 *
 * Übernommen aus dem nie ausgelieferten Zweig wip/gast-link-anrede (Stand
 * 1.134.3): Damals stand im Tab in jedem Zustand „bestätigt“, auch wenn der
 * Gast erst noch den Link in der Mail anklicken musste. Die heutige Seite
 * liest den Titel aus dem Status der Buchung; dieser Test hält das fest.
 */
class ConfirmationPageTitleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, array{bool, ReservationStatus, string, string}>
     */
    public static function states(): array
    {
        $cases = [
            'Restaurant, E-Mail offen' => [false, ReservationStatus::PendingConfirmation, 'E-Mail-Adresse bestätigen'],
            'Restaurant, Anfrage' => [false, ReservationStatus::Requested, 'Anfrage erhalten'],
            'Restaurant, Zahlung offen' => [false, ReservationStatus::PaymentPending, 'Zahlung ausstehend'],
            'Restaurant, bestätigt' => [false, ReservationStatus::Confirmed, 'Reservierung bestätigt'],
            'Salon, E-Mail offen' => [true, ReservationStatus::PendingConfirmation, 'E-Mail-Adresse bestätigen'],
            'Salon, Anfrage' => [true, ReservationStatus::Requested, 'Anfrage erhalten'],
            'Salon, Zahlung offen' => [true, ReservationStatus::PaymentPending, 'Zahlung ausstehend'],
            'Salon, bestätigt' => [true, ReservationStatus::Confirmed, 'Termin bestätigt'],
        ];

        $all = [];
        foreach (['du', 'Sie'] as $address) {
            foreach ($cases as $name => $case) {
                $all[$name.', '.$address] = [...$case, $address];
            }
        }

        return $all;
    }

    #[DataProvider('states')]
    public function test_the_title_names_the_state_of_the_booking(bool $salon, ReservationStatus $status, string $title, string $address): void
    {
        $setup = $this->createTenantSetup();
        if ($salon) {
            $setup['tenant']->update(['type' => TenantType::Salon]);
        }
        $setup['location']->settings()->update(['guest_address' => $address]);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'status' => $status,
            'source' => 'online',
        ]);
        $this->clearTenantContext();

        $this->get(route('booking.confirmation', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertOk()
            ->assertSee('<title>'.$title.'</title>', false);
    }
}

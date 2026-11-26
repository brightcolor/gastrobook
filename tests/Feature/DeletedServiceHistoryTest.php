<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine gelöschte Leistung oder Person verschwindet nicht aus vergangenen
 * Terminen.
 *
 * Leistungen und Personen werden weich gelöscht; das Löschen ist nur möglich,
 * wenn kein kommender Termin sie noch braucht. An vergangenen Terminen hängen
 * sie weiter. Ohne withTrashed in den Beziehungen der Reservierung stand dort
 * danach keine Leistung mehr, und der Export nahm die Zusammenstellung nicht
 * mit, sodass sie beim Umzug verloren ging.
 */
class DeletedServiceHistoryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Ein Salon mit einem vergangenen Termin aus zwei Leistungen bei Anna.
     * Danach werden „Farbe“ und Anna gelöscht.
     *
     * @return array{0: array<string, mixed>, 1: Reservation}
     */
    private function pastAppointmentWithDeletedParts(): array
    {
        $setup = $this->createTenantSetup([]);
        $setup['tenant']->update(['type' => 'salon']);

        $services = collect(['Schnitt' => 60, 'Farbe' => 45])
            ->map(fn (int $minutes, string $name) => Service::create([
                'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
                'name' => $name, 'duration_minutes' => $minutes, 'price_minor' => $minutes * 100, 'is_active' => true,
            ]));
        $anna = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Anna', 'is_active' => true,
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->subDays(3)->setTime(14, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 1, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addMinutes(105)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Completed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
            'service_id' => $services['Farbe']->id, 'staff_member_id' => $anna->id,
        ]);
        $reservation->services()->sync([
            $services['Farbe']->id => ['sort_order' => 0, 'duration_minutes' => 45, 'price_minor' => 4500],
            $services['Schnitt']->id => ['sort_order' => 1, 'duration_minutes' => 60, 'price_minor' => 6000],
        ]);

        $services['Farbe']->delete();
        $anna->delete();

        return [$setup, $reservation];
    }

    public function test_a_past_appointment_keeps_its_deleted_service_and_person(): void
    {
        [, $reservation] = $this->pastAppointmentWithDeletedParts();

        $fresh = Reservation::withoutGlobalScopes()->findOrFail($reservation->id);

        $this->assertSame(['Farbe', 'Schnitt'], $fresh->services->pluck('name')->all());
        $this->assertSame('Farbe', $fresh->service?->name);
        $this->assertSame('Anna', $fresh->staffMember?->name);

        // Auch beim Vorladen mit Spaltenauswahl, wie es Terminplan und Gastbereich tun.
        $loaded = Reservation::withoutGlobalScopes()->with(['services:id,name', 'staffMember:id,name'])->findOrFail($reservation->id);
        $this->assertSame(['Farbe', 'Schnitt'], $loaded->services->pluck('name')->all());
        $this->assertSame('Anna', $loaded->staffMember?->name);
    }

    public function test_the_manage_page_of_a_past_appointment_names_the_deleted_service(): void
    {
        [$setup, $reservation] = $this->pastAppointmentWithDeletedParts();
        $this->clearTenantContext();

        $this->get('/reservation/'.$reservation->code.'/manage/'.$reservation->manage_token)
            ->assertOk()
            ->assertSee('Farbe, Schnitt');
    }

    public function test_a_move_keeps_the_composition_with_a_deleted_service(): void
    {
        [$setup] = $this->pastAppointmentWithDeletedParts();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $setup['tenant']->forceDelete();
        $this->clearTenantContext();

        $target = Tenant::factory()->create(['plan_id' => Plan::factory(), 'type' => 'salon']);
        app(AccountImportService::class)->import($target, $export);

        $moved = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->sole();
        $this->assertSame(['Farbe', 'Schnitt'], $moved->services->pluck('name')->all());
        $this->assertSame(4500, $moved->services->first()->pivot->price_minor);
        $this->assertTrue($moved->services->first()->trashed());
        $this->assertSame('Anna', $moved->staffMember?->name);
    }
}

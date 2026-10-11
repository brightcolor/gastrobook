<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\OpeningHour;
use App\Models\Service;
use App\Services\TimeSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OpeningHoursPreviewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function windows(): array
    {
        return [
            'Abend' => ['17:30', '22:00', 30, 90],
            'über Mitternacht' => ['20:00', '01:30', 15, 150],
            'zu kurz' => ['12:00', '12:45', 20, 60],
            'Takt 45' => ['11:00', '15:00', 45, 60],
        ];
    }

    #[DataProvider('windows')]
    public function test_preview_matches_the_booking_page(string $opens, string $closes, int $interval, int $duration): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => $interval, 'default_duration_minutes' => $duration]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addWeek()->startOfDay();
        $weekday = $day->dayOfWeekIso - 1;
        OpeningHour::where('location_id', $setup['location']->id)->where('weekday', $weekday)->update(['opens_at' => $opens, 'closes_at' => $closes]);
        $expected = array_map(
            fn (CarbonImmutable $s) => $s->format('H:i'),
            app(TimeSlotService::class)->slotStarts($setup['location']->fresh(), $day, $duration),
        );
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => $weekday,
            'windows' => [['opens_at' => $opens, 'closes_at' => $closes]],
        ])->assertOk();

        $this->assertSame($expected, $response->json('starts'));
        $this->assertSame($expected === [] ? null : end($expected), $response->json('last'));
    }

    public function test_reason_names_closing_time_and_duration(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 90]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 4,
            'windows' => [['opens_at' => '18:00', 'closes_at' => '22:00']],
        ])->assertOk();

        $this->assertSame('20:30', $response->json('last'));
        $this->assertSame('Letzte Uhrzeit 20:30: Ein Besuch dauert 90 Minuten, um 22:00 wird geschlossen.', $response->json('reason'));
    }

    public function test_groups_with_their_own_duration_get_their_last_time(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'slot_interval_minutes' => 30, 'default_duration_minutes' => 90,
            'duration_rules' => [['min_party' => 6, 'max_party' => 20, 'duration' => 150]],
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 4,
            'windows' => [['opens_at' => '18:00', 'closes_at' => '22:00']],
        ])->assertOk();

        $this->assertSame([['label' => '6–20 Personen', 'last' => '19:30']], $response->json('groups'));
    }

    public function test_hints_for_short_and_overnight_windows(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 120]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 5,
            'windows' => [['opens_at' => '12:00', 'closes_at' => '13:00'], ['opens_at' => '22:00', 'closes_at' => '02:00']],
        ])->assertOk();

        $this->assertSame([
            ['index' => 0, 'text' => 'Kürzer als ein Besuch (120 Minuten): Hier finden Gäste keine Uhrzeit.'],
            ['index' => 1, 'text' => 'Endet am Folgetag (Sonntag) um 02:00.'],
        ], $response->json('hints'));
    }

    public function test_invalid_windows_answer_422_with_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 9,
            'windows' => [['opens_at' => '25:00', 'closes_at' => '22:00']],
        ])->assertStatus(422)->assertJsonValidationErrors(['weekday', 'windows.0.opens_at']);
    }

    public function test_a_day_without_windows_is_closed(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', ['weekday' => 2, 'windows' => []])->assertOk();

        $this->assertSame([], $response->json('starts'));
        $this->assertNull($response->json('last'));
        $this->assertSame('An diesem Tag ist geschlossen.', $response->json('reason'));
        $this->assertSame([], $response->json('groups'));
        $this->assertSame([], $response->json('hints'));
    }

    public function test_windows_that_are_too_short_say_so(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 120]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 1,
            'windows' => [['opens_at' => '12:00', 'closes_at' => '13:00']],
        ])->assertOk();

        $this->assertSame([], $response->json('starts'));
        $this->assertNull($response->json('last'));
        $this->assertSame('Kein Fenster ist lang genug für einen Besuch von 120 Minuten.', $response->json('reason'));
    }

    public function test_the_preview_uses_the_slot_interval_of_the_location_with_at_least_five_minutes(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 1, 'default_duration_minutes' => 30]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 0,
            'windows' => [['opens_at' => '12:00', 'closes_at' => '13:00']],
        ])->assertOk();

        $this->assertSame(['12:00', '12:05', '12:10', '12:15', '12:20', '12:25', '12:30'], $response->json('starts'));
    }

    public function test_the_number_of_group_lines_comes_from_the_settings(): void
    {
        config(['swayy.opening_hours.preview_groups_max' => 1]);
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'slot_interval_minutes' => 30, 'default_duration_minutes' => 60,
            'duration_rules' => [
                ['min_party' => 1, 'max_party' => 2, 'duration' => 45],
                ['min_party' => 3, 'max_party' => 5, 'duration' => 90],
                ['min_party' => 6, 'max_party' => 20, 'duration' => 150],
            ],
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 4,
            'windows' => [['opens_at' => '18:00', 'closes_at' => '22:00']],
        ])->assertOk();

        $this->assertSame([['label' => '1–2 Personen', 'last' => '21:00']], $response->json('groups'));
    }

    public function test_a_group_without_a_possible_start_has_no_last_time(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'slot_interval_minutes' => 30, 'default_duration_minutes' => 60,
            'duration_rules' => [['min_party' => 8, 'max_party' => 12, 'duration' => 240]],
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 4,
            'windows' => [['opens_at' => '18:00', 'closes_at' => '21:00']],
        ])->assertOk();

        $this->assertSame([['label' => '8–12 Personen', 'last' => null]], $response->json('groups'));
    }

    public function test_a_salon_uses_the_shortest_service_and_its_own_words(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 120]);
        foreach ([['Haarschnitt', 45, true], ['Färben', 120, true], ['Altes Angebot', 15, false]] as [$name, $minutes, $active]) {
            Service::create([
                'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => $name,
                'duration_minutes' => $minutes, 'price_minor' => 3000, 'currency' => 'EUR', 'is_active' => $active,
            ]);
        }
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 2,
            'windows' => [['opens_at' => '09:00', 'closes_at' => '10:00'], ['opens_at' => '12:00', 'closes_at' => '12:30']],
        ])->assertOk();

        $this->assertSame(['09:00'], $response->json('starts'));
        $this->assertSame('Letzte Uhrzeit 09:00: Ein Termin dauert mindestens 45 Minuten, um 10:00 wird geschlossen.', $response->json('reason'));
        $this->assertSame([['label' => 'Färben', 'last' => null]], $response->json('groups'));
        $this->assertSame([['index' => 1, 'text' => 'Kürzer als ein Termin (45 Minuten): Hier finden Kunden keine Uhrzeit.']], $response->json('hints'));
    }

    public function test_a_salon_without_services_uses_the_standard_duration(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 60]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 2,
            'windows' => [['opens_at' => '09:00', 'closes_at' => '11:00']],
        ])->assertOk();

        $this->assertSame(['09:00', '09:30', '10:00'], $response->json('starts'));
        $this->assertSame('Letzte Uhrzeit 10:00: Ein Termin dauert mindestens 60 Minuten, um 11:00 wird geschlossen.', $response->json('reason'));
    }

    public function test_services_of_other_locations_do_not_set_the_duration(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 60]);
        $foreign = $this->createTenantSetup();
        Service::create([
            'tenant_id' => $foreign['tenant']->id, 'location_id' => $foreign['location']->id, 'name' => 'Fremd',
            'duration_minutes' => 15, 'price_minor' => 1000, 'currency' => 'EUR', 'is_active' => true,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 2,
            'windows' => [['opens_at' => '09:00', 'closes_at' => '10:00']],
        ])->assertOk();

        $this->assertSame(['09:00'], $response->json('starts'));
    }

    public function test_overlapping_windows_are_refused_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', [
            'weekday' => 2,
            'windows' => [['opens_at' => '11:00', 'closes_at' => '15:00'], ['opens_at' => '14:00', 'closes_at' => '22:00']],
        ])->assertStatus(422);

        $this->assertSame(
            'Dieses Zeitfenster überschneidet sich mit 11:00–15:00 am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.',
            $response->json('errors')['windows.1.opens_at'][0],
        );
    }

    public function test_a_missing_weekday_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/opening-hours/preview', ['windows' => []])->assertStatus(422);

        $this->assertSame('Dieser Wochentag ist unbekannt. Bitte die Seite neu laden.', $response->json('errors.weekday.0'));
    }
}

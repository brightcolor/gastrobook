<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Location;
use App\Services\SeasonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Jahreslogik der buchbaren Saison: ein Tag ist buchbar, wenn er in
 * mindestens ein wiederkehrendes Fenster fällt. Ohne Fenster gilt keine
 * Einschränkung.
 */
class SeasonServiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function location(): Location
    {
        return $this->createTenantSetup()['location'];
    }

    private function addSeason(Location $location, int $sm, int $sd, int $em, int $ed): void
    {
        $location->seasonPeriods()->create([
            'tenant_id' => $location->tenant_id,
            'start_month' => $sm, 'start_day' => $sd,
            'end_month' => $em, 'end_day' => $ed,
        ]);
    }

    private function bookable(Location $location, string $date): bool
    {
        return app(SeasonService::class)->isBookable($location->fresh(), CarbonImmutable::parse($date));
    }

    public function test_without_any_window_every_day_is_bookable(): void
    {
        $location = $this->location();

        $this->assertTrue($this->bookable($location, '2026-01-01'));
        $this->assertTrue($this->bookable($location, '2026-12-31'));
    }

    public function test_a_normal_window_bounds_the_year(): void
    {
        $location = $this->location();
        $this->addSeason($location, 4, 1, 10, 31); // 1. Apr – 31. Okt

        $this->assertTrue($this->bookable($location, '2026-06-15'));
        $this->assertTrue($this->bookable($location, '2026-04-01'));  // Grenztag
        $this->assertTrue($this->bookable($location, '2026-10-31'));  // Grenztag
        $this->assertFalse($this->bookable($location, '2026-01-01'));
        $this->assertFalse($this->bookable($location, '2026-11-15'));
    }

    public function test_a_window_across_new_year_wraps(): void
    {
        $location = $this->location();
        $this->addSeason($location, 12, 1, 3, 15); // 1. Dez – 15. Mär

        $this->assertTrue($this->bookable($location, '2026-01-01'));
        $this->assertTrue($this->bookable($location, '2026-12-20'));
        $this->assertFalse($this->bookable($location, '2026-06-01'));
    }

    public function test_the_leap_day_follows_the_window_bounds(): void
    {
        $location = $this->location();
        $this->addSeason($location, 2, 1, 2, 28); // 1.–28. Feb

        $this->assertFalse($this->bookable($location, '2028-02-29'));

        $wide = $this->location();
        $this->addSeason($wide, 1, 1, 3, 1); // 1. Jan – 1. Mär
        $this->assertTrue($this->bookable($wide, '2028-02-29'));
    }

    public function test_next_opening_finds_the_upcoming_start(): void
    {
        $location = $this->location();
        $this->addSeason($location, 4, 1, 10, 31);
        $svc = app(SeasonService::class);

        $this->assertSame(
            '2026-04-01',
            $svc->nextOpening($location->fresh(), CarbonImmutable::parse('2026-01-01'))?->toDateString()
        );
        $this->assertSame(
            '2027-04-01',
            $svc->nextOpening($location->fresh(), CarbonImmutable::parse('2026-11-01'))?->toDateString()
        );
    }

    public function test_the_bookable_window_is_trimmed_to_the_season(): void
    {
        $location = $this->location();
        $this->addSeason($location, 11, 23, 1, 10); // Weihnachtsmarkt
        $svc = app(SeasonService::class);
        $loc = $location->fresh();

        $window = $svc->bookableWindow($loc, CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2027-10-07'));
        $this->assertSame('2026-11-23', $window['first']->toDateString());
        $this->assertSame('2027-01-10', $window['last']->toDateString());

        // Endet der Buchungshorizont mitten in der Saison, ist er die Grenze.
        $short = $svc->bookableWindow($loc, CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2027-01-05'));
        $this->assertSame('2027-01-05', $short['last']->toDateString());
    }

    public function test_without_windows_the_bookable_window_is_the_whole_horizon(): void
    {
        $window = app(SeasonService::class)->bookableWindow(
            $this->location()->fresh(),
            CarbonImmutable::parse('2026-10-07'),
            CarbonImmutable::parse('2027-01-05'),
        );

        $this->assertSame('2026-10-07', $window['first']->toDateString());
        $this->assertSame('2027-01-05', $window['last']->toDateString());
    }

    public function test_no_bookable_day_within_the_horizon_gives_null(): void
    {
        $location = $this->location();
        $this->addSeason($location, 4, 1, 4, 30);

        $this->assertNull(app(SeasonService::class)->bookableWindow(
            $location->fresh(),
            CarbonImmutable::parse('2026-10-07'),
            CarbonImmutable::parse('2027-01-05'),
        ));
    }

    public function test_next_opening_is_null_without_windows(): void
    {
        $location = $this->location();

        $this->assertNull(
            app(SeasonService::class)->nextOpening($location->fresh(), CarbonImmutable::parse('2026-01-01'))
        );
    }
}

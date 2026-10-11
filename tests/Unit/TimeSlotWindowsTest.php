<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TimeSlotService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimeSlotWindowsTest extends TestCase
{
    /** @return array<string, array{0: array<int, array{opens_at: string, closes_at: string}>, 1: int, 2: int, 3: array<int, string>}> */
    public static function cases(): array
    {
        return [
            'ein Fenster, Takt 30, Dauer 90' => [[['opens_at' => '18:00', 'closes_at' => '21:00']], 90, 30, ['18:00', '18:30', '19:00', '19:30']],
            'Takt 45' => [[['opens_at' => '12:00', 'closes_at' => '15:00']], 60, 45, ['12:00', '12:45', '13:30']],
            'zu kurz' => [[['opens_at' => '12:00', 'closes_at' => '13:00']], 90, 30, []],
            'über Mitternacht' => [[['opens_at' => '22:00', 'closes_at' => '02:00']], 120, 60, ['22:00', '23:00', '00:00']],
            'zwei Fenster' => [[['opens_at' => '11:30', 'closes_at' => '14:00'], ['opens_at' => '17:00', 'closes_at' => '19:00']], 120, 30, ['11:30', '12:00', '17:00']],
        ];
    }

    /** @param array<int, array{opens_at: string, closes_at: string}> $times */
    #[DataProvider('cases')]
    public function test_starts_for_windows(array $times, int $duration, int $interval, array $expected): void
    {
        $service = app(TimeSlotService::class);
        $day = CarbonImmutable::parse('2026-11-03', 'Europe/Berlin');

        $starts = $service->startsForWindows($service->windowsFromTimes($day, $times), $duration, $interval);

        $this->assertSame($expected, array_map(fn (CarbonImmutable $s) => $s->format('H:i'), $starts));
    }
}

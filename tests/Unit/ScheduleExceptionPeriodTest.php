<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ScheduleExceptionPeriod;
use Tests\TestCase;

class ScheduleExceptionPeriodTest extends TestCase
{
    public function test_the_sheet_of_one_day_has_a_month_and_a_day(): void
    {
        $this->assertSame(['month' => 'Dez.', 'start' => '24', 'end' => null], ScheduleExceptionPeriod::sheet('2026-12-24', '2026-12-24'));
    }

    public function test_the_sheet_of_a_range_in_one_month_names_the_month_once(): void
    {
        $this->assertSame(['month' => 'Dez.', 'start' => '24.', 'end' => '26.'], ScheduleExceptionPeriod::sheet('2026-12-24', '2026-12-26'));
    }

    public function test_the_sheet_of_a_range_over_a_month_boundary_names_both_months(): void
    {
        $this->assertSame(['month' => 'Dez. – Jan.', 'start' => '30.', 'end' => '2.'], ScheduleExceptionPeriod::sheet('2026-12-30', '2027-01-02'));
    }

    public function test_every_month_has_its_german_short_form(): void
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[] = ScheduleExceptionPeriod::sheet(sprintf('2026-%02d-05', $month), sprintf('2026-%02d-05', $month))['month'];
        }

        $this->assertSame(['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'], $months);
    }

    public function test_the_text_names_the_year(): void
    {
        $this->assertSame('24.12.2026', ScheduleExceptionPeriod::text('2026-12-24', '2026-12-24'));
        $this->assertSame('30.12.2026 bis 02.01.2027', ScheduleExceptionPeriod::text('2026-12-30', '2027-01-02'));
    }
}

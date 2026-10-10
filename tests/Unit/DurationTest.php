<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_minutes_read_naturally(): void
    {
        $this->assertSame('45 Minuten', Duration::minutes(45));
        $this->assertSame('1 Minute', Duration::minutes(1));
        $this->assertSame('1 Stunde', Duration::minutes(60));
        $this->assertSame('90 Minuten', Duration::minutes(90));
        $this->assertSame('24 Stunden', Duration::minutes(1440));
        $this->assertSame('2 Tage', Duration::minutes(2880));
        $this->assertSame('36 Stunden', Duration::minutes(2160));
    }
}

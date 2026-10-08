<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FileSize;
use PHPUnit\Framework\TestCase;

class FileSizeTest extends TestCase
{
    public function test_whole_units_stay_whole(): void
    {
        $this->assertSame('3 MB', FileSize::fromKilobytes(3072));
        $this->assertSame('20 MB', FileSize::fromKilobytes(20480));
        $this->assertSame('100 KB', FileSize::fromKilobytes(100));
    }

    public function test_limits_between_units_keep_one_decimal_in_german(): void
    {
        $this->assertSame('1,5 MB', FileSize::fromKilobytes(1536));
        $this->assertSame('1,9 MB', FileSize::fromKilobytes(1900));
    }
}

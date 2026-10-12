<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Location;
use Tests\TestCase;

class LocationRowLockTest extends TestCase
{
    public function test_postgresql_locks_with_for_no_key_update_and_other_databases_with_the_usual_row_lock(): void
    {
        $this->assertSame('for no key update', Location::rowLock('pgsql'));
        $this->assertTrue(Location::rowLock('sqlite'));
        $this->assertTrue(Location::rowLock('mysql'));
    }
}

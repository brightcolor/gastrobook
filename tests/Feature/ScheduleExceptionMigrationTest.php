<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleExceptionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_exists_and_the_migration_runs_twice(): void
    {
        foreach (['special_opening_hours', 'blackout_periods', 'table_blocks'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'exception_group'), $table);
        }

        $migration = require database_path('migrations/2026_10_12_100000_add_exception_group_to_schedule_tables.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('table_blocks', 'exception_group'));
    }

    public function test_every_table_has_an_index_and_down_removes_column_and_index(): void
    {
        $migration = require database_path('migrations/2026_10_12_100000_add_exception_group_to_schedule_tables.php');
        $tables = ['special_opening_hours', 'blackout_periods', 'table_blocks'];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasIndex($table, $table.'_exception_group_index'), $table);
        }

        $migration->down();
        $migration->down();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'exception_group'), $table);
            $this->assertFalse(Schema::hasIndex($table, $table.'_exception_group_index'), $table);
        }

        // Fehlt nur der Index (etwa nach einem abgebrochenen Lauf), legt up() ihn nach.
        Schema::table('blackout_periods', fn ($table) => $table->uuid('exception_group')->nullable());
        $migration->up();
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'exception_group'), $table);
            $this->assertTrue(Schema::hasIndex($table, $table.'_exception_group_index'), $table);
        }
    }
}

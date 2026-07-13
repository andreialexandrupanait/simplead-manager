<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\AppBackup\AppBackupCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P3-35: getTableRowCounts must source approximate counts from the Postgres
 * catalog (pg_class.reltuples) — O(1) per table — instead of a full COUNT(*)
 * scan over every table.
 */
class AppBackupRowCountCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_counts_come_from_catalog_not_full_count(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $counts = app(AppBackupCreator::class)->getTableRowCounts();

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->assertStringContainsString('pg_class', $sql, 'must read from the pg_class catalog');
        $this->assertStringContainsString('reltuples', $sql, 'must use reltuples estimate');
        $this->assertStringNotContainsString('count(*)', $sql, 'must NOT run COUNT(*) over every table');

        // Best-effort result: an array of table => non-negative estimate. Empty /
        // never-analyzed tables tolerate 0 (reltuples is clamped from -1 to 0).
        $this->assertIsArray($counts);
        $this->assertNotEmpty($counts, 'migrated schema should yield at least one table');
        foreach ($counts as $table => $estimate) {
            $this->assertIsString($table);
            $this->assertGreaterThanOrEqual(0, $estimate);
        }
    }
}

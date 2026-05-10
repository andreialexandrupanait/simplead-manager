<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards columns that are read or written by hot, every-minute code paths.
 *
 * If a migration "Ran" row is recorded but the DDL didn't actually apply (as happened
 * 2026-04 with auto_retry_count → 9 days of broken backups), this test catches it.
 */
class CriticalSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function criticalColumnsProvider(): array
    {
        return [
            // table => [column], read/written by BackupDispatcher every minute
            'backups.auto_retry_count' => ['backups', 'auto_retry_count'],
            'backups.status' => ['backups', 'status'],
            'backups.stage' => ['backups', 'stage'],
            'backups.started_at' => ['backups', 'started_at'],
            'backup_configs.is_enabled' => ['backup_configs', 'is_enabled'],
            'backup_configs.next_backup_at' => ['backup_configs', 'next_backup_at'],
            'app_backup_configs.is_enabled' => ['app_backup_configs', 'is_enabled'],
            'app_backup_configs.next_backup_at' => ['app_backup_configs', 'next_backup_at'],
            'sites.is_connected' => ['sites', 'is_connected'],
        ];
    }

    /**
     * @dataProvider criticalColumnsProvider
     */
    public function test_critical_column_exists(string $table, string $column): void
    {
        $this->assertTrue(
            Schema::hasColumn($table, $column),
            "Critical column missing: {$table}.{$column}. ".
            'A migration may be marked as ran without having applied its DDL — check the migrations table.'
        );
    }
}

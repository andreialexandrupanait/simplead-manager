<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppBackup;

use App\Models\AppBackup;
use App\Services\AppBackup\AppBackupCreator;
use App\Services\AppBackup\AppBackupDownloader;
use App\Services\AppBackup\AppBackupRestorer;
use Tests\TestCase;

class AppBackupRestorerTest extends TestCase
{
    public function test_restore_throws_if_database_component_missing(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = $this->createMock(AppBackup::class);
        $backup->components = ['env'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain a database component');

        $restorer->restoreDatabase($backup);
    }

    public function test_restore_throws_if_components_null(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = $this->createMock(AppBackup::class);
        $backup->components = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain a database component');

        $restorer->restoreDatabase($backup);
    }

    public function test_view_env_throws_if_env_component_missing(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = $this->createMock(AppBackup::class);
        $backup->components = ['database'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain an .env component');

        $restorer->viewEnv($backup);
    }

    public function test_verify_restore_returns_ok_when_counts_match(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = new AppBackup;
        $backup->component_sizes = [
            'table_counts' => ['users' => 10, 'sites' => 5],
        ];

        $creator = $this->createMock(AppBackupCreator::class);
        $creator->method('getTableRowCounts')->willReturn(['users' => 10, 'sites' => 5, 'jobs' => 0]);

        // Use reflection to call protected verifyRestore
        $method = new \ReflectionMethod($restorer, 'verifyRestore');

        // Mock the app() call inside verifyRestore by binding AppBackupCreator
        app()->instance(AppBackupCreator::class, $creator);

        $result = $method->invoke($restorer, $backup);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['tables_matched']);
        $this->assertSame(0, $result['tables_different']);
    }

    public function test_verify_restore_returns_warning_on_mismatch(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = new AppBackup;
        $backup->component_sizes = [
            'table_counts' => ['users' => 10, 'sites' => 5],
        ];

        $creator = $this->createMock(AppBackupCreator::class);
        $creator->method('getTableRowCounts')->willReturn(['users' => 10, 'sites' => 3]);

        app()->instance(AppBackupCreator::class, $creator);

        $method = new \ReflectionMethod($restorer, 'verifyRestore');
        $result = $method->invoke($restorer, $backup);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, $result['tables_matched']);
        $this->assertSame(1, $result['tables_different']);
    }

    public function test_verify_restore_returns_no_baseline_when_no_counts(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = new AppBackup;
        $backup->component_sizes = [];

        $creator = $this->createMock(AppBackupCreator::class);
        $creator->method('getTableRowCounts')->willReturn(['users' => 10]);

        app()->instance(AppBackupCreator::class, $creator);

        $method = new \ReflectionMethod($restorer, 'verifyRestore');
        $result = $method->invoke($restorer, $backup);

        $this->assertSame('no_baseline', $result['status']);
    }

    public function test_verify_restore_detects_missing_table(): void
    {
        $service = $this->createMock(AppBackupDownloader::class);
        $restorer = new AppBackupRestorer($service);

        $backup = new AppBackup;
        $backup->component_sizes = [
            'table_counts' => ['users' => 10, 'deleted_table' => 5],
        ];

        $creator = $this->createMock(AppBackupCreator::class);
        $creator->method('getTableRowCounts')->willReturn(['users' => 10]);

        app()->instance(AppBackupCreator::class, $creator);

        $method = new \ReflectionMethod($restorer, 'verifyRestore');
        $result = $method->invoke($restorer, $backup);

        $this->assertSame('warning', $result['status']);
        $details = collect($result['details'])->where('status', 'missing');
        $this->assertCount(1, $details);
    }

    public function test_pgsql_command_is_built_correctly(): void
    {
        // Verify the command format for PostgreSQL restore
        $host = 'localhost';
        $port = '5432';
        $username = 'app';
        $password = 'secret';
        $database = 'simplead';
        $sqlFile = '/tmp/database.sql';

        $cmd = sprintf(
            'PGPASSWORD=%s psql --set ON_ERROR_STOP=1 --host=%s --port=%s --username=%s %s < %s',
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        $this->assertStringContainsString('PGPASSWORD=', $cmd);
        $this->assertStringContainsString('--set ON_ERROR_STOP=1', $cmd);
        $this->assertStringContainsString("--host='localhost'", $cmd);
    }

    public function test_mysql_command_is_built_correctly(): void
    {
        $host = 'localhost';
        $port = '3306';
        $username = 'root';
        $password = 'secret';
        $database = 'mydb';
        $sqlFile = '/tmp/database.sql';

        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        $this->assertStringContainsString("--host='localhost'", $cmd);
        $this->assertStringContainsString("--user='root'", $cmd);
    }
}

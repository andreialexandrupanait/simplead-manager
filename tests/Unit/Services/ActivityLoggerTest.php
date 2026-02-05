<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class ActivityLoggerTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_log_creates_activity_log_record(): void
    {
        $site = $this->createSite();

        $log = ActivityLogger::log(
            type: 'uptime',
            severity: 'info',
            title: 'Test log entry',
            site: $site,
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $site->id,
            'type' => 'uptime',
            'severity' => 'info',
            'title' => 'Test log entry',
        ]);
    }

    public function test_log_includes_metadata(): void
    {
        $site = $this->createSite();

        $log = ActivityLogger::log(
            type: 'update',
            severity: 'info',
            title: 'Plugin updated',
            site: $site,
            metadata: ['plugin' => 'akismet', 'from' => '5.2', 'to' => '5.3'],
        );

        $this->assertEquals('akismet', $log->metadata['plugin']);
        $this->assertEquals('5.2', $log->metadata['from']);
        $this->assertEquals('5.3', $log->metadata['to']);
    }

    public function test_site_down_creates_critical_activity_log(): void
    {
        $site = $this->createSite();

        $log = ActivityLogger::siteDown($site, 'Connection timeout');

        $this->assertEquals('uptime', $log->type);
        $this->assertEquals('critical', $log->severity);
        $this->assertStringContainsString('is down', $log->title);
        $this->assertEquals('Connection timeout', $log->description);
        $this->assertEquals('Connection timeout', $log->metadata['reason']);
    }

    public function test_site_recovered_creates_info_activity_log(): void
    {
        $site = $this->createSite();

        $log = ActivityLogger::siteRecovered($site, 15);

        $this->assertEquals('uptime', $log->type);
        $this->assertEquals('success', $log->severity);
        $this->assertStringContainsString('back up', $log->title);
        $this->assertStringContainsString('15 minutes', $log->description);
        $this->assertEquals(15, $log->metadata['downtime_minutes']);
    }

    public function test_backup_completed_creates_info_activity_log(): void
    {
        $site = $this->createSite();

        $log = ActivityLogger::backupCompleted($site, 'backup-2024-01-01.zip', 52428800);

        $this->assertEquals('backup', $log->type);
        $this->assertEquals('success', $log->severity);
        $this->assertStringContainsString('Backup completed', $log->title);
        $this->assertStringContainsString('backup-2024-01-01.zip', $log->description);
        $this->assertEquals('backup-2024-01-01.zip', $log->metadata['file_name']);
        $this->assertEquals(52428800, $log->metadata['file_size']);
    }

    public function test_log_works_without_optional_parameters(): void
    {
        $log = ActivityLogger::log(
            type: 'system',
            severity: 'info',
            title: 'System event',
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertNull($log->site_id);
        $this->assertNull($log->description);
        $this->assertNull($log->metadata);
        $this->assertNull($log->icon);
        $this->assertNull($log->url);
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'system',
            'severity' => 'info',
            'title' => 'System event',
        ]);
    }
}

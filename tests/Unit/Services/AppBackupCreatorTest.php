<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AppBackup\AppBackupCreator;
use PHPUnit\Framework\TestCase;

class AppBackupCreatorTest extends TestCase
{
    private AppBackupCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = new AppBackupCreator;
    }

    public function test_resolve_components_full_backup(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'full');
        $this->assertSame(['database', 'env', 'storage'], $result);
    }

    public function test_resolve_components_database_only(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'database');
        $this->assertSame(['database'], $result);
    }

    public function test_resolve_components_config_only(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'config');
        $this->assertSame(['env'], $result);
    }

    public function test_resolve_components_storage_only(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'storage');
        $this->assertSame(['storage'], $result);
    }

    public function test_resolve_components_unknown_type_defaults_to_full(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'unknown');
        $this->assertSame(['database', 'env', 'storage'], $result);
    }

    public function test_resolve_components_with_logs_option(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'full', ['include_logs' => true]);
        $this->assertContains('logs', $result);
        $this->assertContains('database', $result);
    }

    public function test_resolve_components_with_codebase_option(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'full', ['include_codebase' => true]);
        $this->assertContains('codebase', $result);
    }

    public function test_resolve_components_with_all_options(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        $result = $method->invoke($this->creator, 'full', [
            'include_logs' => true,
            'include_codebase' => true,
        ]);

        $this->assertSame(['database', 'env', 'storage', 'logs', 'codebase'], $result);
    }

    public function test_resolve_components_deduplicates(): void
    {
        $method = new \ReflectionMethod($this->creator, 'resolveComponents');

        // 'storage' type with no options should not have duplicates
        $result = $method->invoke($this->creator, 'storage', []);
        $this->assertSame(['storage'], $result);
        $this->assertCount(1, $result);
    }

    public function test_format_bytes_helper(): void
    {
        $method = new \ReflectionMethod($this->creator, 'formatBytes');

        // Just verify it doesn't throw — actual formatting is in FormatHelper
        $result = $method->invoke($this->creator, 1024);
        $this->assertIsString($result);
    }
}

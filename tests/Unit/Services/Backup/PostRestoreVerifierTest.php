<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Backup;
use App\Services\Backup\PostRestoreVerifier;
use Tests\TestCase;

class PostRestoreVerifierTest extends TestCase
{
    private PostRestoreVerifier $verifier;

    private WordPressApiServiceInterface $api;

    private array $progressCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new PostRestoreVerifier;
        $this->api = $this->createMock(WordPressApiServiceInterface::class);
        $this->progressCalls = [];
    }

    private function makeBackup(): Backup
    {
        $backup = $this->createMock(Backup::class);
        $backup->id = 42;

        return $backup;
    }

    private function reportProgress(): \Closure
    {
        return function (string $stage, int $percent, string $message) {
            $this->progressCalls[] = compact('stage', 'percent', 'message');
        };
    }

    public function test_full_successful_verification(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => ['step1', 'step2']]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willReturn([
            'loopback' => ['status' => 200],
            'paused_extensions' => [],
        ]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('cache cleared', $result);
        $this->assertStringContainsString('Elementor fixed (2 steps)', $result);
        $this->assertStringContainsString('Elementor reinitialized', $result);
        $this->assertStringContainsString('site check (HTTP 200)', $result);
        $this->assertStringNotContainsString('plugin update failed', $result);
    }

    public function test_plugin_update_failed_flag(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willReturn([]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), false, $this->reportProgress());

        $this->assertStringContainsString('plugin update failed', $result);
    }

    public function test_cache_clear_failure_is_graceful(): void
    {
        $this->api->method('clearCache')->willThrowException(new \Exception('Connection timeout'));
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willThrowException(new \Exception('Elementor not installed'));
        $this->api->method('runDiagnostic')->willReturn([]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('cache clear failed', $result);
    }

    public function test_elementor_fix_failure_is_graceful(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willThrowException(new \Exception('Plugin not active'));
        $this->api->method('deactivatePlugin')->willThrowException(new \Exception('not installed'));
        $this->api->method('runDiagnostic')->willReturn([]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('Elementor fix skipped', $result);
    }

    public function test_elementor_not_installed(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->expects($this->atLeastOnce())
            ->method('deactivatePlugin')
            ->willThrowException(new \Exception('Elementor is not installed'));
        $this->api->method('runDiagnostic')->willReturn([]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('Elementor not installed', $result);
    }

    public function test_diagnostic_detects_paused_extensions(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willReturn([
            'loopback' => ['status' => 200],
            'paused_extensions' => ['plugin-a', 'plugin-b'],
        ]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('WARNING: paused extensions detected', $result);
    }

    public function test_diagnostic_failure_is_graceful(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willThrowException(new \Exception('503'));

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('diagnostic skipped', $result);
    }

    public function test_progress_is_reported_for_each_step(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willReturn([]);

        $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $stages = array_column($this->progressCalls, 'stage');
        $this->assertContains('verification', $stages);
        $this->assertGreaterThanOrEqual(4, count($this->progressCalls));
    }

    public function test_result_string_has_semicolon_delimiters(): void
    {
        $this->api->method('clearCache')->willReturn(['success' => true]);
        $this->api->method('fixElementor')->willReturn(['fixed' => []]);
        $this->api->method('deactivatePlugin')->willReturn(['success' => true]);
        $this->api->method('activatePlugin')->willReturn(['success' => true]);
        $this->api->method('runDiagnostic')->willReturn([
            'loopback' => ['status' => 200],
        ]);

        $result = $this->verifier->verify($this->api, $this->makeBackup(), true, $this->reportProgress());

        $this->assertStringContainsString('; ', $result);
    }
}

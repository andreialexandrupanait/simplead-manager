<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\Client\Response;

interface WordPressApiServiceInterface
{
    // Core request methods
    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response;

    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response;

    // Backup & download methods
    public function setBackupMode(bool $enabled): void;

    public function resetThrottle(): void;

    public function getBackupCapabilities(): ?array;

    public function chunkedDownloadFilesAsChunks(string $saveTo, ?callable $onProgress = null): array;

    public function chunkedDownload(string $type, string $saveTo, ?callable $onProgress = null, ?callable $onCheckCancelled = null): void;

    public function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void;

    public function streamDownload(string $endpoint, string $saveTo): void;

    // Cron management
    public function getCronList(): array;

    public function runCron(string $hook, ?array $args = null): array;

    public function disableCron(string $hook, ?array $args = null): array;

    public function enableCron(string $hook, string $schedule, ?array $args = null): array;

    // Plugin management
    public function getPlugins(): array;

    public function updatePlugins(array $pluginFiles): array;

    public function activatePlugin(string $pluginFile): array;

    public function deactivatePlugin(string $pluginFile): array;

    public function deletePlugin(string $pluginFile): array;

    // Theme management
    public function getThemes(): array;

    public function updateThemes(array $themeSlugs): array;

    public function activateTheme(string $themeSlug): array;

    public function deleteTheme(string $themeSlug): array;

    // User management
    public function getUsers(): array;

    public function createUser(array $data): array;

    public function updateUser(int $wpUserId, array $data): array;

    public function deleteUser(int $wpUserId, ?int $reassignTo = null): array;

    // Security
    public function getSecurityCheck(): array;

    public function pushSecuritySettings(array $settings): array;

    public function getSecurityState(): array;

    public function applySecurityFix(string $key): array;

    // Database
    public function getDbCleanupStats(): array;

    public function runDbCleanup(array $options): array;

    public function getDatabaseHealth(): array;

    public function optimizeTable(string $tableName): array;

    public function convertTableEngine(string $tableName): array;

    public function deleteTable(string $tableName): array;

    // Site info
    public function getInfo(): array;

    public function getLoginUrl(?string $user = null): array;

    public function getCoreIntegrityCheck(): array;

    public function updateCore(): array;

    public function rollback(string $type, string $slug, string $version): array;

    public function healthCheck(): array;

    public function runDiagnostic(): array;

    public function fixElementor(): array;

    public function clearCache(): array;

    public function getServerResources(): array;
}

<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\WordPressApiServiceInterface;
use BadMethodCallException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

/**
 * Scriptable fake for the WP connector API.
 *
 * Every method delegates to a scripted response set via script()/scriptMany().
 * Unscripted calls throw BadMethodCallException so tests fail loudly instead
 * of silently passing on a wrong contract. Defaults mirror the REAL connector
 * response shapes (e.g. /health returns ['success','healthy',...] — there is
 * no 'status' key) so tests exercise the same contract production sees.
 */
class FakeWordPressApiService implements WordPressApiServiceInterface
{
    /** @var array<string, mixed> canned return values (or callables) keyed by method name */
    private array $scripted = [];

    /** @var list<array{method: string, args: array<mixed>}> every call received, in order */
    public array $calls = [];

    public function __construct()
    {
        // Sensible healthy-site default; override per-test via script().
        $this->scripted['healthCheck'] = self::healthyResponse();
    }

    /** A realistic healthy /health payload (connector shape). */
    public static function healthyResponse(array $overrides = []): array
    {
        return array_merge([
            'success' => true,
            'healthy' => true,
            'wp_version' => '6.8.1',
            'php_version' => '8.3.0',
            'database_ok' => true,
            'uploads_writable' => true,
            'ssl_active' => true,
            'cron_disabled' => false,
            'plugin_updates' => 0,
            'theme_updates' => 0,
            'core_update' => false,
            'plugin_version' => '2.15.0',
        ], $overrides);
    }

    /** A realistic /plugins/update result keyed by plugin FILE (connector shape). */
    public static function updateResult(string $file, bool $success = true, ?string $error = null, string $from = '1.0.0', string $to = '1.1.0'): array
    {
        return [
            'success' => true,
            'results' => [
                $file => array_filter([
                    'success' => $success,
                    'from_version' => $from,
                    'to_version' => $to,
                    'error' => $error,
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    /** Script a return value (or a callable receiving the call args) for a method. */
    public function script(string $method, mixed $result): static
    {
        $this->scripted[$method] = $result;

        return $this;
    }

    /** @param array<string, mixed> $responses */
    public function scriptMany(array $responses): static
    {
        foreach ($responses as $method => $result) {
            $this->script($method, $result);
        }

        return $this;
    }

    /** All recorded calls to a given method. */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    public function assertCalled(string $method): void
    {
        \PHPUnit\Framework\Assert::assertNotEmpty(
            $this->callsTo($method),
            "Expected {$method}() to be called on FakeWordPressApiService, but it was not."
        );
    }

    public function assertNotCalled(string $method): void
    {
        \PHPUnit\Framework\Assert::assertEmpty(
            $this->callsTo($method),
            "Expected {$method}() NOT to be called on FakeWordPressApiService, but it was."
        );
    }

    public function assertCalledWith(string $method, array $args): void
    {
        foreach ($this->callsTo($method) as $call) {
            if ($call['args'] === $args) {
                \PHPUnit\Framework\Assert::assertTrue(true);

                return;
            }
        }

        \PHPUnit\Framework\Assert::fail(
            "Expected {$method}() to be called with ".var_export($args, true)
            .'. Recorded calls: '.var_export($this->callsTo($method), true)
        );
    }

    private function respond(string $method, array $args): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (! array_key_exists($method, $this->scripted)) {
            throw new BadMethodCallException(
                "FakeWordPressApiService::{$method}() was called but not scripted. "
                ."Script it with ->script('{$method}', ...) in your test."
            );
        }

        $result = $this->scripted[$method];

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return is_callable($result) ? $result(...$args) : $result;
    }

    /** Build an Illuminate HTTP client Response from an array payload. */
    private function toResponse(mixed $payload, int $status = 200): Response
    {
        if ($payload instanceof Response) {
            return $payload;
        }

        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($payload)));
    }

    // Core request methods

    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        return $this->toResponse($this->respond('request', func_get_args()));
    }

    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response
    {
        return $this->toResponse($this->respond('requestRaw', func_get_args()));
    }

    // Backup & download methods

    public function setBackupMode(bool $enabled): void
    {
        $this->respond('setBackupMode', func_get_args());
    }

    public function resetThrottle(): void
    {
        $this->respond('resetThrottle', func_get_args());
    }

    public function getBackupCapabilities(): ?array
    {
        return $this->respond('getBackupCapabilities', func_get_args());
    }

    public function chunkedDownloadFilesAsChunks(string $saveTo, ?callable $onProgress = null): array
    {
        return $this->respond('chunkedDownloadFilesAsChunks', func_get_args());
    }

    public function chunkedDownload(string $type, string $saveTo, ?callable $onProgress = null, ?callable $onCheckCancelled = null): void
    {
        $this->respond('chunkedDownload', func_get_args());
    }

    public function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void
    {
        $this->respond('streamDownloadTo', func_get_args());
    }

    public function streamDownload(string $endpoint, string $saveTo): void
    {
        $this->respond('streamDownload', func_get_args());
    }

    // Cron

    public function getCronList(): array
    {
        return $this->respond('getCronList', func_get_args());
    }

    public function runCron(string $hook, ?array $args = null): array
    {
        return $this->respond('runCron', func_get_args());
    }

    public function disableCron(string $hook, ?array $args = null): array
    {
        return $this->respond('disableCron', func_get_args());
    }

    public function enableCron(string $hook, string $schedule, ?array $args = null): array
    {
        return $this->respond('enableCron', func_get_args());
    }

    // Plugins

    public function getPlugins(): array
    {
        return $this->respond('getPlugins', func_get_args());
    }

    public function updatePlugins(array $pluginFiles): array
    {
        return $this->respond('updatePlugins', func_get_args());
    }

    public function activatePlugin(string $pluginFile): array
    {
        return $this->respond('activatePlugin', func_get_args());
    }

    public function deactivatePlugin(string $pluginFile): array
    {
        return $this->respond('deactivatePlugin', func_get_args());
    }

    public function deletePlugin(string $pluginFile): array
    {
        return $this->respond('deletePlugin', func_get_args());
    }

    // Themes

    public function getThemes(): array
    {
        return $this->respond('getThemes', func_get_args());
    }

    public function updateThemes(array $themeSlugs): array
    {
        return $this->respond('updateThemes', func_get_args());
    }

    public function activateTheme(string $themeSlug): array
    {
        return $this->respond('activateTheme', func_get_args());
    }

    public function deleteTheme(string $themeSlug): array
    {
        return $this->respond('deleteTheme', func_get_args());
    }

    // Users

    public function getUsers(): array
    {
        return $this->respond('getUsers', func_get_args());
    }

    public function createUser(array $data): array
    {
        return $this->respond('createUser', func_get_args());
    }

    public function updateUser(int $wpUserId, array $data): array
    {
        return $this->respond('updateUser', func_get_args());
    }

    public function deleteUser(int $wpUserId, ?int $reassignTo = null): array
    {
        return $this->respond('deleteUser', func_get_args());
    }

    public function bulkDeleteUsers(array $wpUserIds, ?int $reassignTo = null): array
    {
        return $this->respond('bulkDeleteUsers', func_get_args());
    }

    // Security

    public function getSecurityCheck(): array
    {
        return $this->respond('getSecurityCheck', func_get_args());
    }

    public function pushSecuritySettings(array $settings): array
    {
        return $this->respond('pushSecuritySettings', func_get_args());
    }

    public function getSecurityState(): array
    {
        return $this->respond('getSecurityState', func_get_args());
    }

    public function applySecurityFix(string $key): array
    {
        return $this->respond('applySecurityFix', func_get_args());
    }

    // Database

    public function getDbCleanupStats(): array
    {
        return $this->respond('getDbCleanupStats', func_get_args());
    }

    public function runDbCleanup(array $options): array
    {
        return $this->respond('runDbCleanup', func_get_args());
    }

    public function getDatabaseHealth(): array
    {
        return $this->respond('getDatabaseHealth', func_get_args());
    }

    public function optimizeTable(string $tableName): array
    {
        return $this->respond('optimizeTable', func_get_args());
    }

    public function convertTableEngine(string $tableName): array
    {
        return $this->respond('convertTableEngine', func_get_args());
    }

    public function deleteTable(string $tableName): array
    {
        return $this->respond('deleteTable', func_get_args());
    }

    public function getAutoloadAudit(): array
    {
        return $this->respond('getAutoloadAudit', func_get_args());
    }

    public function getConfigHealth(): array
    {
        return $this->respond('getConfigHealth', func_get_args());
    }

    // Site info

    public function getInfo(): array
    {
        return $this->respond('getInfo', func_get_args());
    }

    public function getLoginUrl(?string $user = null): array
    {
        return $this->respond('getLoginUrl', func_get_args());
    }

    public function getCoreIntegrityCheck(): array
    {
        return $this->respond('getCoreIntegrityCheck', func_get_args());
    }

    public function getThemeIntegrityCheck(string $slug): array
    {
        return $this->respond('getThemeIntegrityCheck', func_get_args());
    }

    public function updateCore(): array
    {
        return $this->respond('updateCore', func_get_args());
    }

    public function rollback(string $type, string $slug, string $version): array
    {
        return $this->respond('rollback', func_get_args());
    }

    public function healthCheck(): array
    {
        return $this->respond('healthCheck', func_get_args());
    }

    public function runDiagnostic(): array
    {
        return $this->respond('runDiagnostic', func_get_args());
    }

    public function fixElementor(): array
    {
        return $this->respond('fixElementor', func_get_args());
    }

    public function clearCache(): array
    {
        return $this->respond('clearCache', func_get_args());
    }

    public function getServerResources(): array
    {
        return $this->respond('getServerResources', func_get_args());
    }

    // Error logs

    public function getErrorLogs(int $limit = 100): array
    {
        return $this->respond('getErrorLogs', func_get_args());
    }

    // Key management

    public function rotateApiKeys(): array
    {
        return $this->respond('rotateApiKeys', func_get_args());
    }

    // Posts

    public function createPost(array $data): array
    {
        return $this->respond('createPost', func_get_args());
    }

    public function getPostCategories(): array
    {
        return $this->respond('getPostCategories', func_get_args());
    }

    public function getPostTags(): array
    {
        return $this->respond('getPostTags', func_get_args());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Plugin\SimpleadBackupClient;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

/**
 * HTTP proof of the authenticated restore transport against the LIVE simplead-backup plugin on
 * spike-wp: the manager PUSHES a materialised file chunk with an HMAC-signed raw body
 * (restore/stage-chunk), then applies + commits a staged, atomic swap — and the file physically
 * lands on the WordPress host (confirmed by a scoped re-backup of the target dir).
 *
 * Scoped to a throwaway subdir under ABSPATH so it never disturbs the real install. Skipped (never
 * failed) when the plugin is not reachable. The correctness of the swap/rollback/modes is proven
 * exhaustively in RestoreEngineTest + RestoreRunnerE2ETest; this test is specifically the
 * authenticated HTTP round-trip (signing + plugin auth + real WP filesystem).
 */
#[Group('e2e')]
#[Group('restore')]
class RestoreHttpE2ETest extends TestCase
{
    private static function wpHost(): string
    {
        // Default is the sam_spike_net container alias; override to the host-published
        // port (http://127.0.0.1:18080) when the runner is on the host network.
        return getenv('BACKUP_ENGINE_V2_LAB_WP_HOST') ?: 'http://sam_spike-spike-wp-1';
    }

    private const DIR = 'wp-content/sam-http-restore-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPluginReady();
    }

    public function test_authenticated_http_restore_lands_a_file_on_the_host(): void
    {
        $client = SimpleadBackupClient::lab(self::wpHost());
        $token = 'httprestore'.bin2hex(random_bytes(4));
        $rel = self::DIR.'/hello_'.bin2hex(random_bytes(3)).'.txt';
        $content = 'restored-over-http-'.bin2hex(random_bytes(8));

        $zip = $this->makeZip([$rel => $content]);

        try {
            // prepare — open a scoped, files-only restore session.
            $prep = $client->restorePrepare($token, [
                'mode' => 'safe_merge',
                'scope' => ['database' => false, 'files' => true, 'paths' => [self::DIR]],
                'mirror_roots' => [self::DIR],
                'keep_paths' => [$rel],
            ]);
            $this->assertTrue((bool) ($prep['ok'] ?? false), 'prepare must succeed');

            // stage-chunk — HMAC-signed RAW body; the plugin re-verifies sha256.
            $staged = $client->restoreStageChunk($token, 'files', 0, $zip);
            $this->assertTrue((bool) ($staged['ok'] ?? false), 'stage-chunk must succeed');
            $this->assertSame(hash_file('sha256', $zip), $staged['sha256'] ?? null, 'plugin must confirm the staged sha256');

            $status = $client->restoreStatus($token);
            $this->assertContains($status['state'] ?? null, ['staging', 'prepared']);

            // apply — staged→live atomic swap.
            $applied = $client->restoreApply($token);
            $this->assertTrue((bool) ($applied['applied'] ?? false), 'apply must report applied');
            $this->assertNotNull($applied['files'] ?? null, 'apply must report a file swap');

            $client->restoreCommit($token);
        } finally {
            @unlink($zip);
        }

        // Confirm the file physically landed on the host: a scoped re-backup of the target dir must
        // now inventory exactly this file, and its chunk must contain the exact content.
        $this->assertFileLandedOnHost($client, $rel, $content);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function assertFileLandedOnHost(SimpleadBackupClient $client, string $rel, string $content): void
    {
        $rule = [['type' => 'glob', 'value' => self::DIR.'/**', 'action' => 'include']];
        $session = 'httpverify'.bin2hex(random_bytes(4));

        $inv = $client->filesInventory($session, $rule, ['include_defaults' => true, 'compression' => 'store']);
        $this->assertGreaterThanOrEqual(1, (int) ($inv['total_files'] ?? 0), 'the restored file must be present on the host');

        // Materialise + pull chunk 0 and confirm our exact content is inside.
        $found = false;
        for ($i = 0; $i < (int) ($inv['chunk_count'] ?? 0); $i++) {
            $exec = $client->filesChunkExec($session, $i);
            if (! empty($exec['empty'])) {
                continue;
            }
            $chunk = $client->filesChunkDownload($session, $i, deleteAfter: true);
            $zip = new ZipArchive;
            if ($zip->open($chunk->path) === true) {
                $bytes = $zip->getFromName($rel);
                $zip->close();
                if ($bytes !== false && hash_equals($content, (string) $bytes)) {
                    $found = true;
                }
            }
            $chunk->delete();
            if ($found) {
                break;
            }
        }

        $this->assertTrue($found, "restored file {$rel} not found (or wrong content) on the host after HTTP restore");
    }

    /**
     * @param  array<string,string>  $files
     */
    private function makeZip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'httprestore_').'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $rel => $content) {
            $zip->addFromString($rel, $content);
        }
        $zip->close();

        return $path;
    }

    private function skipUnlessPluginReady(): void
    {
        try {
            $caps = SimpleadBackupClient::lab(self::wpHost())->capabilities();
        } catch (\Throwable $e) {
            $this->markTestSkipped('simplead-backup plugin not reachable at '.self::wpHost().': '.$e->getMessage());
        }
        if (($caps['plugin']['name'] ?? null) !== 'simplead-backup') {
            $this->markTestSkipped('simplead-backup plugin not active on '.self::wpHost());
        }
    }
}

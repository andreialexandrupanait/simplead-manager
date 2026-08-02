<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Plugin\PluginClientException;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Support\RequiresLab;
use Tests\TestCase;
use ZipArchive;

/**
 * The fourth of the four things that had to be true before this engine could be trusted: a restore
 * whose manager disappears mid-flight reconciles itself, rather than being rolled back or applied
 * twice by whoever picks the job up next.
 *
 * The other three were proven long ago. This one was left open because proving it means killing the
 * watcher in the middle of an operation, and the only real WordPress available for that had been a
 * client's site. It is proven here against the lab's WordPress, over HTTP, through the actual
 * plugin — which is where the untested half lived: the re-entry guard, the detached apply, and
 * whether the site can still say what happened after nobody was listening.
 *
 * The failure this prevents is the one that already happened once in production, in its own words:
 * "the manager recorded failed / rolled back to pre-apply while the site was completely and
 * correctly restored."
 */
#[Group('e2e')]
#[Group('restore')]
class RestoreInterruptedReconciliationTest extends TestCase
{
    use RequiresLab;

    private const DIR = 'wp-content/sam-reconcile-test';

    private static function wpHost(): string
    {
        return getenv('BACKUP_ENGINE_V2_LAB_WP_HOST') ?: 'http://sam_spike-spike-wp-1';
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $caps = SimpleadBackupClient::lab(self::wpHost())->capabilities();
        } catch (\Throwable $e) {
            $this->labUnavailable('simplead-backup plugin not reachable at '.self::wpHost().': '.$e->getMessage());
        }
        if (($caps['plugin']['name'] ?? null) !== 'simplead-backup') {
            $this->labUnavailable('simplead-backup plugin not active on '.self::wpHost());
        }
    }

    public function test_an_apply_nobody_is_watching_finishes_and_can_still_be_asked_about(): void
    {
        $client = SimpleadBackupClient::lab(self::wpHost());
        $token = 'reconcile'.bin2hex(random_bytes(4));
        $rel = self::DIR.'/reconciled_'.bin2hex(random_bytes(3)).'.txt';
        $content = 'survived-the-interruption-'.bin2hex(random_bytes(8));

        $zip = $this->makeZip([$rel => $content]);

        try {
            $client->restorePrepare($token, [
                'mode' => 'safe_merge',
                'scope' => ['database' => false, 'files' => true, 'paths' => [self::DIR]],
                'mirror_roots' => [self::DIR],
                'keep_paths' => [$rel],
            ]);
            $client->restoreStageChunk($token, 'files', 0, $zip);

            // Hand the work over and walk away. From here on nothing is holding the site's hand:
            // this is the manager's worker being killed the instant after it dispatched.
            $detached = $client->restoreSupportsAsync()
                ? $client->restoreApplyAsync($token)
                : ['async' => false];

            if (! ($detached['async'] ?? false)) {
                // No loopback and no cron on this host — then there is no detached apply to
                // interrupt, and the property under test does not apply here.
                $this->markTestSkipped('the lab host cannot detach an apply; nothing to interrupt');
            }

            // A redelivered job that simply tried again would land here. It must be turned away:
            // a second apply clears the first one's rollback data, which is how a restore that
            // worked becomes unrecoverable.
            $this->assertSecondApplyIsRefused($client, $token);

            // Deliver the detached request ourselves.
            //
            // On a real host the plugin's own loopback does this — proven on feaagalati.ro, where
            // restores run this way. It cannot happen in the lab: WordPress there is installed at
            // http://127.0.0.1:18080, which from inside its own container points at nothing, so a
            // loopback is dispatched into the void. What is under test is what happens once the
            // detached request arrives, so the test plays the part of the loopback, signing the
            // same internal route with the same credentials.
            $this->landTheDetachedRequest($token);

            // Now the reconciliation itself: ask the site what happened.
            $state = $this->pollUntilTerminal($client, $token);

            $this->assertSame('applied', $state, 'the site finishes the work whether or not anyone is watching');
        } finally {
            @unlink($zip);
        }

        // Committing from a different "process" than the one that started it — the redelivered job
        // completing the transaction the dead one opened.
        $client->restoreCommit($token);

        $after = $client->restoreStatus($token);
        $this->assertSame(
            'committed',
            $after['state'] ?? null,
            'the status has to outlive the tidying up: it is what a later job reads to find out '
            .'whether the restore it inherited had finished',
        );

        $this->assertFileLandedOnHost($client, $rel, $content);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function assertSecondApplyIsRefused(SimpleadBackupClient $client, string $token): void
    {
        try {
            $second = $client->restoreApply($token);
        } catch (PluginClientException $e) {
            // The guard, doing its job: an apply is already running for this token.
            $reason = strtolower((string) ($e->payload['error']['message'] ?? $e->getMessage()));
            $this->assertStringContainsString(
                'already',
                $reason,
                'the refusal must say why, or the manager cannot tell it from a broken host',
            );

            return;
        }

        // The other acceptable answer: the first apply had already finished, and the plugin
        // recognises the repeat rather than redoing it.
        $this->assertTrue(
            (bool) ($second['already'] ?? false),
            'a second apply must be refused or recognised as a repeat, never performed again',
        );
    }

    /**
     * The request the plugin's loopback would have made, signed the same way, and abandoned the
     * moment it is accepted — nobody waits for a detached apply.
     */
    private function landTheDetachedRequest(string $token): void
    {
        $route = '/simplead-backup/v1/restore/apply-execute';
        $body = (string) json_encode(['token' => $token]);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $signature = hash_hmac(
            'sha256',
            implode('|', ['POST', $route, $timestamp, $nonce, $body]),
            (string) config('backup_v2.plugin.secret'),
        );

        try {
            Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-SAM-Backup-Key' => (string) config('backup_v2.plugin.key'),
                    'X-SAM-Backup-Timestamp' => $timestamp,
                    'X-SAM-Backup-Nonce' => $nonce,
                    'X-SAM-Backup-Signature' => $signature,
                ])
                ->timeout(2)
                ->post(rtrim(self::wpHost(), '/').'/wp-json'.$route);
        } catch (\Throwable) {
            // Expected: we hang up while it works, exactly as the loopback does. `ignore_user_abort`
            // on the far side is what makes that safe, and whether it really is safe is the point.
        }
    }

    private function pollUntilTerminal(SimpleadBackupClient $client, string $token): string
    {
        $deadline = time() + 90;

        while (time() < $deadline) {
            $status = $client->restoreStatus($token);
            $state = (string) ($status['state'] ?? 'unknown');

            if (in_array($state, ['applied', 'failed', 'committed', 'rolled_back'], true)) {
                return $state;
            }

            usleep(500_000);
        }

        $this->fail('the apply never reached a terminal state within 90s');
    }

    private function assertFileLandedOnHost(SimpleadBackupClient $client, string $rel, string $content): void
    {
        $rule = [['type' => 'glob', 'value' => self::DIR.'/**', 'action' => 'include']];
        $session = 'reconcileverify'.bin2hex(random_bytes(4));

        $inv = $client->filesInventory($session, $rule, ['include_defaults' => true, 'compression' => 'store']);

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
                $found = $bytes !== false && hash_equals($content, (string) $bytes);
            }
            $chunk->delete();
            if ($found) {
                break;
            }
        }

        $this->assertTrue($found, "the restored file {$rel} is not on the host after the interrupted restore");
    }

    /**
     * @param  array<string,string>  $files
     */
    private function makeZip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'reconcile_').'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $rel => $content) {
            $zip->addFromString($rel, $content);
        }
        $zip->close();

        return $path;
    }
}

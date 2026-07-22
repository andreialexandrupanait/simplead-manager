<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * C-08: proves a backup is genuinely restorable by restoring it into the
 * isolated sandbox WordPress (a real WP running the SAM connector, registered as
 * an internal `is_sandbox` Site) and health-checking the result — homepage 200,
 * login reachable, connector loopback OK, and DB row counts coherent with the
 * backup manifest. Self-contained: does NOT touch the RestoreBackup job, so the
 * origin site/backup bookkeeping is never mutated by a proof run.
 */
class SandboxRestoreService
{
    private const RESTORE_TIMEOUT = 1800;

    public function __construct(
        private readonly WordPressApiServiceFactory $apiFactory,
        private readonly ManifestService $manifestService,
    ) {}

    /**
     * Restore $backup into $sandbox and health-check it.
     *
     * @return array{passed: bool, checks: array<string,mixed>, error: ?string}
     */
    public function prove(Site $sandbox, Backup $backup): array
    {
        try {
            $this->restoreInto($sandbox, $backup);
        } catch (\Throwable $e) {
            return ['passed' => false, 'checks' => [], 'error' => 'restore failed: '.$e->getMessage()];
        }

        return $this->runHealthChecks($sandbox, $backup);
    }

    /**
     * Materialise the backup archive and push it into the sandbox connector via
     * the same staged swap used for a real restore. Only the v3-zip format is
     * supported for proof runs (the pilots use it); other formats throw.
     */
    public function restoreInto(Site $sandbox, Backup $backup): void
    {
        if ($backup->format !== 'v3-zip') {
            throw new \RuntimeException("Proven restore supports v3-zip backups only; got '{$backup->format}'.");
        }

        $workDir = storage_path('app/temp/proven-restore-'.uniqid());
        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new \RuntimeException("Could not create work dir {$workDir}");
        }

        try {
            [$filesZip, $dbGz] = $this->materialiseV3($backup, $workDir);

            $api = $this->apiFactory->make($sandbox);

            // Files first, then database — same ordering as a real restore. A full
            // (non-selective) proof always uses the atomic staged swap.
            if ($filesZip !== null) {
                $this->stageAndSend($api, 'files', $filesZip);
            }
            if ($dbGz !== null) {
                $this->stageAndSend($api, 'database', $dbGz);
            }
        } finally {
            $this->rrmdir($workDir);
        }
    }

    /**
     * @return array{passed: bool, checks: array<string,mixed>, error: ?string}
     */
    public function runHealthChecks(Site $sandbox, Backup $backup): array
    {
        $checks = [];

        // 1. Homepage responds 200.
        $home = $this->httpStatus(rtrim($sandbox->url, '/').'/');
        $checks['homepage_200'] = $home === 200;

        // 2. Login screen reachable.
        $login = $this->httpStatus(rtrim($sandbox->url, '/').'/wp-login.php');
        $checks['login_reachable'] = in_array($login, [200, 302], true);

        // 3. Connector loopback (the site can reach itself — catches fatal errors).
        try {
            $diagnostic = $this->apiFactory->make($sandbox)->runDiagnostic();
            $loopback = $diagnostic['loopback']['status'] ?? null;
            $checks['loopback_ok'] = $loopback !== null && (int) $loopback >= 200 && (int) $loopback < 400;
        } catch (\Throwable $e) {
            $checks['loopback_ok'] = false;
        }

        // 4. DB coherence: the restored DB should carry roughly the row counts the
        // manifest recorded at backup time (proves the dump imported, not just that
        // WP booted on an empty DB).
        $checks['db_coherent'] = $this->dbCoherentWithManifest($sandbox, $backup);

        $passed = ! in_array(false, $checks, true);

        return ['passed' => $passed, 'checks' => $checks, 'error' => null];
    }

    private function dbCoherentWithManifest(Site $sandbox, Backup $backup): bool
    {
        try {
            $manifest = $this->manifestService->retrieve($backup);
        } catch (\Throwable) {
            // No manifest to compare against (older backups) — don't fail the proof
            // on its absence; the homepage/login/loopback checks still gate it.
            return true;
        }

        $expected = (int) ($manifest['database']['total_rows'] ?? $manifest['total_rows'] ?? 0);
        if ($expected <= 0) {
            // No baseline to compare against — don't fail the whole proof on a
            // missing manifest metric; the other checks still gate it.
            return true;
        }

        try {
            $diagnostic = $this->apiFactory->make($sandbox)->runDiagnostic();
            $actual = (int) ($diagnostic['database']['total_rows'] ?? 0);
        } catch (\Throwable) {
            return false;
        }

        // Allow a small drift (transients/sessions written on boot).
        return $actual >= (int) floor($expected * 0.9);
    }

    /**
     * Download the v3 archive, extract, and repackage into (files.zip, db.sql.gz).
     *
     * @return array{0: ?string, 1: ?string} paths to files.zip and database.sql.gz (null if absent)
     */
    private function materialiseV3(Backup $backup, string $workDir): array
    {
        $destination = $backup->storageDestination;
        if (! $destination) {
            throw new \RuntimeException('Backup has no storage destination to download from.');
        }

        $local = $workDir.'/'.($backup->file_name ?: 'backup.zip');
        StorageFactory::make($destination)->download((string) $backup->file_path, $local);

        if (! file_exists($local) || filesize($local) === 0) {
            throw new \RuntimeException('Downloaded backup archive is empty or missing.');
        }

        if ($backup->checksum && hash_file('sha256', $local) !== $backup->checksum) {
            throw new \RuntimeException('Backup checksum mismatch — archive is corrupt.');
        }

        $zip = new ZipArchive;
        if ($zip->open($local) !== true) {
            throw new \RuntimeException('Failed to open v3-zip archive.');
        }
        SafeZipExtractor::extractTo($zip, $workDir);
        $zip->close();
        @unlink($local);

        $dbGz = is_file($workDir.'/database.sql.gz') ? $workDir.'/database.sql.gz' : null;

        // Re-pack the files/ subtree with WP paths at the archive root.
        $filesDir = $workDir.'/files';
        $filesZip = null;
        if (is_dir($filesDir)) {
            $filesZip = $workDir.'/files.zip';
            $repack = new ZipArchive;
            if ($repack->open($filesZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create files.zip.');
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($filesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $repack->addFile($file->getRealPath(), substr($file->getRealPath(), strlen($filesDir) + 1));
                }
            }
            $repack->close();
        }

        return [$filesZip, $dbGz];
    }

    private function stageAndSend(object $api, string $type, string $filePath): void
    {
        $token = bin2hex(random_bytes(32));
        $staged = storage_path("app/temp/restore-{$token}");

        try {
            copy($filePath, $staged);
            $downloadUrl = rtrim((string) config('app.url'), '/').'/restore-download/'.$token;

            $response = $api->request('POST', '/backup/restore', [
                'type' => $type,
                'download_url' => $downloadUrl,
                'file_mode' => 'staged',
            ], [], self::RESTORE_TIMEOUT);
            $response->throw();
        } finally {
            @unlink($staged);
        }
    }

    private function httpStatus(string $url): int
    {
        try {
            return Http::timeout(15)->get($url)->status();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

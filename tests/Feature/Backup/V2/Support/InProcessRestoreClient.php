<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Restore\RestoreClient;
use Closure;
use SAM_Backup_Restore_Engine;

/**
 * A RestoreClient that drives the ACTUAL plugin restore engine (SAM_Backup_Restore_Engine, loaded
 * from the plugin source, CLI-guarded so it loads under phpunit) against a controllable "live site"
 * temp dir — the same tree the InProcessPluginClient backed up. This lets the RestoreRunner E2E pull
 * real chunk objects from real MinIO and apply them through the SAME staged/journaled swap code the
 * HTTP plugin uses, proving the full FSM round-trip without WP or HTTP. (Files-only: the DB half
 * needs mysqli and is proven over HTTP against spike-wp.)
 */
final class InProcessRestoreClient implements RestoreClient
{
    /** @var array<string, SAM_Backup_Restore_Engine> */
    private array $engines = [];

    private string $workRoot;

    /** @var Closure(string):void|null crash injector shared with the active engine */
    private ?Closure $fault = null;

    public function __construct(private readonly string $liveRoot)
    {
        $base = rtrim((string) getenv('SAM_PLUGIN_SRC') ?: base_path('wordpress-plugin/simplead-backup'), '/');
        require_once $base.'/includes/restore/class-restore-engine.php';
        $this->workRoot = sys_get_temp_dir().'/sam_restore_work_'.uniqid('', true);
    }

    /** Inject a crash into the next apply's file swap (kill-mid-restore proof). */
    public function faultOnSwap(?Closure $fault): void
    {
        $this->fault = $fault;
    }

    private function engine(string $token): SAM_Backup_Restore_Engine
    {
        if (! isset($this->engines[$token])) {
            $engine = new SAM_Backup_Restore_Engine($token, $this->liveRoot, $this->workRoot.'/'.$token, []);
            if ($this->fault !== null) {
                $engine->set_fault($this->fault);
            }
            $this->engines[$token] = $engine;
        }

        return $this->engines[$token];
    }

    public function restorePrepare(string $token, array $opts): array
    {
        return $this->engine($token)->prepare($opts);
    }

    public function restoreStageChunk(string $token, string $kind, int $seq, string $localPath): array
    {
        $sha = (string) hash_file('sha256', $localPath);

        return $kind === 'files'
            ? $this->engine($token)->stage_files_chunk($seq, $localPath, $sha)
            : $this->engine($token)->stage_db_chunk($seq, $localPath, $sha);
    }

    public function restoreApply(string $token): array
    {
        return $this->engine($token)->apply();
    }

    public function restoreSupportsAsync(): bool
    {
        return false;
    }

    /**
     * In-process there is nothing to detach to — no loopback, no cron — which is exactly the answer
     * a locked-down shared host gives. Reporting it honestly keeps these tests on the synchronous
     * path rather than polling a status that will never change.
     */
    public function restoreApplyAsync(string $token): array
    {
        return ['ok' => true, 'async' => false, 'token' => $token, 'reason' => 'in-process client cannot detach'];
    }

    public function restoreCommit(string $token): array
    {
        return $this->engine($token)->commit();
    }

    public function restoreRollback(string $token): array
    {
        return $this->engine($token)->rollback();
    }

    public function restoreStatus(string $token): array
    {
        return $this->engine($token)->status();
    }

    public function cleanup(): void
    {
        if (is_dir($this->workRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->workRoot);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Audit;

use App\DTOs\Audit\SfExports;
use App\Enums\AuditRunStatus;
use App\Enums\AuditStatus;
use App\Enums\CheckState;
use App\Enums\CrawlSource;
use App\Enums\ProspectProfile;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\AuditCheckResult;
use App\Models\AuditRun;
use App\Services\Audit\AuditEvaluator;
use App\Services\Audit\SfCrawlLoader;
use App\Services\Audit\SfCrawlRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faza D (D2b): runs the Screaming Frog headless crawl for one audit, then
 * ingests the exports into a normalized manifest (evaluation is Faza D3).
 *
 * Guards mirror the audit repo's sf-crawl job:
 *  - a SINGLE SF crawl at a time (WithoutOverlapping on a global key) — SF is
 *    memory-heavy (-Xmx2g) and we crawl clients at 1 URL/sec;
 *  - 30 min SF timeout (the job timeout sits just above it);
 *  - no auto-retry (tries=1): a crawl is expensive, so a failure surfaces on the
 *    run row + log rather than silently re-crawling.
 */
class RunSfCrawl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Just above the SF crawl timeout so SF's own kill fires first. */
    public int $timeout = 1900;

    /** @var list<string> */
    private array $logLines = [];

    private ?AuditRun $run = null;

    public function __construct(
        public int $auditId,
        public CrawlSource $source = CrawlSource::SfHeadless,
    ) {
        $this->onQueue('audit');
    }

    /**
     * One SF crawl fleet-wide. The audit supervisor also runs a single process,
     * but this holds the invariant even if another supervisor is added.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('audit:sf-crawl'))->releaseAfter(120)->expireAfter(2000)];
    }

    public function handle(SfCrawlRunner $runner, SfCrawlLoader $loader): void
    {
        $audit = Audit::find($this->auditId);
        if ($audit === null) {
            Log::warning("RunSfCrawl: audit {$this->auditId} no longer exists — skipping.");

            return;
        }

        $outputFolder = rtrim((string) config('audit.crawls_base_dir'), '/')."/audit_{$audit->id}";

        $this->run = $audit->runs()->create([
            'source' => $this->source,
            'status' => AuditRunStatus::Running,
            'crawl_dir' => $outputFolder,
            'started_at' => now(),
        ]);
        $startedAt = microtime(true);

        try {
            if ($audit->status === AuditStatus::Configurat) {
                $audit->update(['status' => AuditStatus::Colectare]);
                $this->log('Audit moved to COLECTARE.');
            }

            if (! is_dir($outputFolder) && ! @mkdir($outputFolder, 0775, true) && ! is_dir($outputFolder)) {
                throw new \RuntimeException("Could not create crawl folder: {$outputFolder}");
            }

            // A manual upload has already populated the folder; only the headless
            // source runs SF.
            if ($this->source === CrawlSource::SfHeadless) {
                $this->log("Starting Screaming Frog crawl for {$audit->url} → {$outputFolder}");
                $runner->crawl($audit->url, $outputFolder);
                $this->log('Screaming Frog crawl finished.');
            } else {
                $this->log("Ingesting manually uploaded exports from {$outputFolder}");
            }

            $exports = $loader->load($outputFolder);
            $present = 0;
            foreach ($exports->files as $file) {
                if ($file->present) {
                    $present++;
                }
            }
            $total = count($exports->files);
            $manifest = [
                'present' => $present,
                'absent' => $total - $present,
                'unmatched' => count($exports->unmatchedFiles),
                'total' => $total,
            ];
            $this->log(sprintf(
                'Ingest complete: %d/%d exports present, %d unmatched CSVs.',
                $present, $total, $manifest['unmatched'],
            ));

            $this->evaluateAndPersist($audit, $exports);

            $this->run->update([
                'status' => AuditRunStatus::Done,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'manifest' => $manifest,
                'log' => $this->logLines,
            ]);
        } catch (Throwable $e) {
            $this->run->update([
                'status' => AuditRunStatus::Failed,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'log' => $this->logLines,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Run the v2 evaluators over the ingested crawl and persist one result per
     * check (state + cited evidence). Deterministic sources only for now — PSI
     * collection is wired in a follow-up (psiMobile stays null, so 3.5/3.6 remain
     * manual). state_set_by=auto; the auditor overrides in the editor.
     */
    private function evaluateAndPersist(Audit $audit, SfExports $exports): void
    {
        $checks = AuditCheck::query()->get(['id', 'key', 'applicability']);
        $checkList = $checks->map(static fn (AuditCheck $c): array => [
            'key' => $c->key,
            'applicability' => $c->applicability,
        ])->all();
        $profile = $audit->prospect?->profile;
        $clientProfile = $profile instanceof ProspectProfile ? $profile->value : '';

        $evaluations = (new AuditEvaluator)->evaluate($exports, $audit->url, $clientProfile, $checkList);

        $idByKey = $checks->pluck('id', 'key');
        $now = now();
        $counts = ['EXISTA' => 0, 'NU_EXISTA' => 0, 'NU_SE_APLICA' => 0, 'manual' => 0];
        foreach ($evaluations as $key => $eval) {
            $checkId = $idByKey[$key] ?? null;
            if ($checkId === null) {
                continue;
            }
            AuditCheckResult::updateOrCreate(
                ['audit_id' => $audit->id, 'audit_check_id' => $checkId],
                ['state' => $eval->state, 'evidence' => $eval->evidence, 'state_set_by' => 'auto', 'collected_at' => $now],
            );
            $counts[$eval->state instanceof CheckState ? $eval->state->value : 'manual']++;
        }

        $this->log(sprintf(
            'Evaluated %d checks: %d EXISTA, %d NU_EXISTA, %d NU_SE_APLICA, %d left manual.',
            count($evaluations), $counts['EXISTA'], $counts['NU_EXISTA'], $counts['NU_SE_APLICA'], $counts['manual'],
        ));
    }

    public function failed(Throwable $e): void
    {
        // The run row is the surfaced state; ensure it reflects failure even if
        // the job died outside the try/catch (e.g. SIGKILL on timeout).
        if ($this->run !== null && $this->run->status !== AuditRunStatus::Failed) {
            $this->run->update(['status' => AuditRunStatus::Failed, 'error' => $e->getMessage()]);
        }
        Log::error("RunSfCrawl failed for audit {$this->auditId}: {$e->getMessage()}");
    }

    private function log(string $line): void
    {
        $this->logLines[] = $line;
        Log::info("[sf-crawl audit {$this->auditId}] {$line}");
    }
}

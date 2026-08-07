<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MigrateSiteToBackupV2;
use App\Models\Site;
use App\Services\Backup\FleetMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Put the backup engine on any connected site still missing it.
 *
 * The engine could only ever be installed by hand, from a console at /backup-v2/fleet that nothing
 * in the application linked to. So a site that was connected after the fleet migration simply stayed
 * on the old engine, quietly, and the only signal was someone eventually asking why. That is not a
 * migration that finished; it is one that stopped being run.
 *
 * The same shape as dns:backfill-monitors — a safety net that only touches sites missing the thing,
 * so running it twice does nothing the second time and nothing at all once the fleet is whole.
 *
 * Deliberately narrow in two directions:
 *
 *   - **It never pushes the connector.** FleetMigrationService::migrate() would, as its first step,
 *     and a scheduled command that updates the connector across the fleet overnight is a different
 *     and much larger decision than installing a backup engine. Sites needing one are reported and
 *     skipped, so the push stays a thing a person does knowing they did it.
 *   - **It never enables a schedule.** Backing a site up spends a client's storage. A site with no
 *     nightly schedule is reported, not started.
 */
class BackfillBackupEngine extends Command
{
    protected $signature = 'backup:backfill-engine
                            {--dry-run : List what would be migrated, dispatch nothing}';

    protected $description = 'Install the backup engine on any connected site still missing it';

    /**
     * Seconds between dispatches. Each job is a plugin install against somebody else's WordPress;
     * released together they would arrive as a burst of thirty simultaneous outbound installs.
     */
    private const STAGGER_SECONDS = 15;

    public function handle(FleetMigrationService $migration): int
    {
        $runId = (string) Str::uuid();
        $dryRun = (bool) $this->option('dry-run');

        /** @var list<string> $queued */
        $queued = [];
        /** @var list<array{site: string, reason: string}> $skipped */
        $skipped = [];
        $alreadyDone = 0;
        $unscheduled = 0;

        foreach (Site::connected()->get() as $site) {
            $status = $migration->status($site);

            if (! $status['ready']) {
                $skipped[] = ['site' => $site->domain, 'reason' => (string) $status['blocked_by']];

                continue;
            }

            if (! $status['scheduled']) {
                $unscheduled++;
            }

            if ($status['steps'] === []) {
                $alreadyDone++;

                continue;
            }

            // The connector is the one step this command will not take on its own; see the class
            // docblock. Reported rather than silently left out, because a site stuck here looks
            // exactly like one that is fine until you ask why it never migrated.
            if (in_array('connector', $status['steps'], true)) {
                $skipped[] = [
                    'site' => $site->domain,
                    'reason' => __('Needs a connector push first (has :version, needs :required).', [
                        'version' => $status['connector'] ?: '—',
                        'required' => FleetMigrationService::MIN_CONNECTOR,
                    ]),
                ];

                continue;
            }

            if (! $dryRun) {
                MigrateSiteToBackupV2::dispatch($site, $runId)
                    ->delay(now()->addSeconds(count($queued) * self::STAGGER_SECONDS));
            }

            $queued[] = $site->domain;
        }

        $this->report($queued, $skipped, $alreadyDone, $unscheduled, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $queued
     * @param  list<array{site: string, reason: string}>  $skipped
     */
    private function report(array $queued, array $skipped, int $alreadyDone, int $unscheduled, bool $dryRun): void
    {
        $verb = $dryRun ? 'would migrate' : 'migrating';

        foreach ($queued as $domain) {
            $this->line("  {$verb}  {$domain}");
        }

        foreach ($skipped as $row) {
            $this->line("  skipped   {$row['site']} — {$row['reason']}");
        }

        $this->newLine();
        $this->info(sprintf(
            '%d already on the engine, %d %s, %d skipped.',
            $alreadyDone,
            count($queued),
            $dryRun ? 'to migrate' : 'queued',
            count($skipped),
        ));

        if ($unscheduled > 0) {
            $this->warn(sprintf(
                '%d connected site(s) have no nightly schedule — the engine alone does not back them up.',
                $unscheduled,
            ));
        }

        // Scheduled runs have nobody reading stdout. Without this the command's whole point — that a
        // site is missing the engine and nobody noticed — would go unnoticed in exactly the same way.
        if ($dryRun) {
            return;
        }

        if ($queued !== [] || $skipped !== []) {
            Log::info('Backup engine backfill', [
                'queued' => $queued,
                'skipped' => $skipped,
                'already_on_engine' => $alreadyDone,
                'without_schedule' => $unscheduled,
            ]);
        }
    }
}

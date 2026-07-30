<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SiteTask;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §11 — pull each site's tasks from app.simplead.ro.
 *
 * Linking a site to its upstream project is done by NAME, because that is how
 * the projects are actually named: 26 of the 40 sites match on the domain alone
 * ("asic.ro", "motivonti.ro"), and most of the rest match once punctuation and
 * the TLD are dropped ("M1 Beauty" ↔ m1-beauty.at, "Hotel Simona Halep" ↔
 * hotelsimonahalep.ro). Anything that does not match is left alone rather than
 * guessed at — `sites.external_project_id` can be set by hand, and once set it
 * is never overwritten by the matcher.
 */
class SiteTaskSyncService
{
    public function __construct(
        private readonly SimpleAdAppClient $client = new SimpleAdAppClient,
    ) {}

    /**
     * Sync every linkable site. Returns per-site task counts.
     *
     * @return array{sites: int, tasks: int, linked: int}
     */
    public function syncAll(): array
    {
        if (! $this->client->enabled()) {
            return ['sites' => 0, 'tasks' => 0, 'linked' => 0];
        }

        $projects = $this->client->projects();

        if ($projects === []) {
            return ['sites' => 0, 'tasks' => 0, 'linked' => 0];
        }

        $byKey = [];
        foreach ($projects as $project) {
            $key = $this->normalise($project['name']);
            if ($key !== '') {
                $byKey[$key] = $project['id'];
            }
        }

        $sites = 0;
        $tasks = 0;
        $linked = 0;

        Site::query()->whereNull('deleted_at')->each(function (Site $site) use ($byKey, &$sites, &$tasks, &$linked): void {
            $projectId = $site->external_project_id;

            if ($projectId === null || $projectId === '') {
                $projectId = $this->matchProject($site, $byKey);

                if ($projectId === null) {
                    return;
                }

                $site->forceFill(['external_project_id' => $projectId])->saveQuietly();
                $linked++;
            }

            $sites++;
            $tasks += $this->syncSite($site, $projectId);
        });

        return ['sites' => $sites, 'tasks' => $tasks, 'linked' => $linked];
    }

    /**
     * Pull one site's tasks. Returns how many were stored.
     */
    public function syncSite(Site $site, ?string $projectId = null): int
    {
        $projectId ??= $site->external_project_id;

        if ($projectId === null || $projectId === '') {
            return 0;
        }

        $tasks = $this->client->tasksForProject($projectId);

        if ($tasks === []) {
            return 0;
        }

        $seen = [];

        foreach ($tasks as $task) {
            try {
                SiteTask::updateOrCreate(
                    ['site_id' => $site->id, 'external_id' => $task['external_id']],
                    $task + ['synced_at' => now()],
                );
                $seen[] = $task['external_id'];
            } catch (\Throwable $e) {
                Log::warning("Task sync failed for site {$site->id} / {$task['external_id']}: {$e->getMessage()}");
            }
        }

        // A task deleted upstream should stop showing here too, but only when we
        // genuinely received a list — never on an empty/failed response.
        if ($seen !== []) {
            SiteTask::where('site_id', $site->id)->whereNotIn('external_id', $seen)->delete();
        }

        return count($seen);
    }

    /**
     * @param  array<string, string>  $byKey  normalised project name => project id
     */
    private function matchProject(Site $site, array $byKey): ?string
    {
        foreach ([$site->url, $site->name] as $candidate) {
            $key = $this->normalise((string) $candidate);

            if ($key !== '' && isset($byKey[$key])) {
                return $byKey[$key];
            }
        }

        return null;
    }

    /**
     * Reduce a project name or a site URL to a comparable key: host only, no
     * www, no TLD, letters and digits only. "https://m1-beauty.at" and
     * "M1 Beauty" both become "m1beauty".
     */
    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#', $value)) {
            $value = parse_url($value, PHP_URL_HOST) ?: $value;
        }

        $value = preg_replace('/^www\./', '', $value) ?? $value;
        $value = preg_replace('/\.(ro|com|at|hu|de|net|org|eu|io|co\.uk|com\.au|nl|hr|ch|bg)$/', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }
}

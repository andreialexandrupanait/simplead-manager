<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use App\Models\StatusPageSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class StatusPageService
{
    public static function createAutoIncident(StatusPage $statusPage, Site $site, string $reason): StatusPageIncident
    {
        // Don't create duplicate auto-incidents
        $existing = StatusPageIncident::where('status_page_id', $statusPage->id)
            ->where('site_id', $site->id)
            ->where('auto_created', true)
            ->active()
            ->first();

        if ($existing) {
            return $existing;
        }

        /** @var StatusPageIncident $incident */
        $incident = $statusPage->incidents()->create([
            'site_id' => $site->id,
            'title' => "{$site->name} is experiencing issues",
            'description' => $reason,
            'status' => 'investigating',
            'severity' => 'major',
            'started_at' => now(),
            'auto_created' => true,
        ]);

        $incident->updates()->create([
            'status' => 'investigating',
            'message' => "We are investigating issues with {$site->name}. Reason: {$reason}",
        ]);

        return $incident;
    }

    public static function resolveAutoIncident(StatusPage $statusPage, Site $site): void
    {
        $incidents = StatusPageIncident::where('status_page_id', $statusPage->id)
            ->where('site_id', $site->id)
            ->where('auto_created', true)
            ->active()
            ->get();

        foreach ($incidents as $incident) {
            /** @var StatusPageIncident $incident */
            $incident->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);

            $incident->updates()->create([
                'status' => 'resolved',
                'message' => "{$site->name} has recovered and is operating normally.",
            ]);
        }
    }

    public static function getPublicData(StatusPage $statusPage): array
    {
        return Cache::remember("status-page:{$statusPage->id}", 60, function () use ($statusPage) { // @phpstan-ignore argument.type
            /** @var \Illuminate\Database\Eloquent\Collection<int, StatusPageSite> $statusPageSites */
            $statusPageSites = $statusPage->statusPageSites()
                ->where('is_visible', true)
                ->with(['site.uptimeMonitor'])
                ->get();

            $sites = $statusPageSites->map(fn (StatusPageSite $sps) => [
                'name' => $sps->name,
                'status' => $sps->current_status,
                'uptime_percentage' => $sps->site?->uptime_percentage,
                'response_time' => $sps->site?->uptimeMonitor?->avg_response_time,
            ]);

            $activeIncidents = $statusPage->activeIncidents()
                ->with('updates')
                ->orderByDesc('started_at')
                ->get();

            $recentIncidents = StatusPageIncident::where('status_page_id', $statusPage->id)
                ->where('status', 'resolved')
                ->recent($statusPage->incident_history_days)
                ->with('updates')
                ->orderByDesc('started_at')
                ->get();

            $scheduledMaintenance = $statusPage->incidents()
                ->where('is_scheduled', true)
                ->where('status', '!=', 'resolved')
                ->orderBy('scheduled_start_at')
                ->get();

            // SLA computation
            $sla = null;
            if ($statusPage->show_sla && $statusPage->sla_target) {
                $sla = static::computeSla($statusPageSites, (float) $statusPage->sla_target);
            }

            return [ // @phpstan-ignore return.type
                'title' => $statusPage->title,
                'description' => $statusPage->description,
                'logo_url' => $statusPage->logo_url,
                'primary_color' => $statusPage->primary_color,
                'overall_status' => $statusPage->overall_status,
                'show_uptime_percentage' => $statusPage->show_uptime_percentage,
                'show_response_time' => $statusPage->show_response_time,
                'show_incident_history' => $statusPage->show_incident_history,
                'sites' => $sites,
                'active_incidents' => $activeIncidents,
                'recent_incidents' => $recentIncidents,
                'scheduled_maintenance' => $scheduledMaintenance,
                'sla' => $sla,
            ];
        });
    }

    /**
     * Compute SLA data: current month actual uptime vs target, plus last 3 months history.
     */
    /** @param \Illuminate\Database\Eloquent\Collection<int, StatusPageSite> $sites */
    protected static function computeSla(\Illuminate\Database\Eloquent\Collection $sites, float $target): array
    {
        /** @var \Illuminate\Support\Collection<int, \App\Models\UptimeMonitor> $monitors */
        $monitors = $sites->map(fn ($sps) => $sps->site?->uptimeMonitor)->filter();

        if ($monitors->isEmpty()) {
            return ['target' => $target, 'current' => null, 'met' => null, 'history' => []];
        }

        // Current month average uptime across all monitors
        $currentUptime = round((float) $monitors->avg('uptime_30d'), 3);
        $met = $currentUptime >= $target;

        // Monthly history (last 3 months from snapshots)
        $history = [];
        for ($i = 1; $i <= 3; $i++) {
            $date = now()->subMonths($i);
            $year = (int) $date->format('Y');
            $month = (int) $date->format('n');

            $siteIds = $sites->pluck('site_id')->filter();
            $avg = \App\Models\SiteMonthlySnapshot::whereIn('site_id', $siteIds)
                ->where('year', $year)
                ->where('month', $month)
                ->whereNotNull('uptime_percentage')
                ->avg('uptime_percentage');

            $history[] = [
                'month' => $date->format('M Y'),
                'uptime' => $avg !== null ? round((float) $avg, 3) : null,
                'met' => $avg !== null ? (float) $avg >= $target : null,
            ];
        }

        return [
            'target' => $target,
            'current' => $currentUptime,
            'met' => $met,
            'history' => $history,
        ];
    }

    public static function verifyPassword(StatusPage $statusPage, string $password): bool
    {
        if (! $statusPage->password_hash) {
            return true;
        }

        return Hash::check($password, $statusPage->password_hash);
    }
}

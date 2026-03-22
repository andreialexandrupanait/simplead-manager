<?php

namespace App\Services;

use App\Models\Site;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class StatusPageService
{
    public static function createAutoIncident(StatusPage $statusPage, Site $site, string $reason): StatusPageIncident
    {
        // Don't create duplicate auto-incidents
        $existing = $statusPage->incidents()
            ->where('site_id', $site->id)
            ->where('auto_created', true)
            ->active()
            ->first();

        if ($existing) {
            return $existing;
        }

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
        $incidents = $statusPage->incidents()
            ->where('site_id', $site->id)
            ->where('auto_created', true)
            ->active()
            ->get();

        foreach ($incidents as $incident) {
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
        return Cache::remember("status-page:{$statusPage->id}", 60, function () use ($statusPage) {
            $sites = $statusPage->statusPageSites()
                ->where('is_visible', true)
                ->with(['site.uptimeMonitor'])
                ->get()
                ->map(fn ($sps) => [
                    'name' => $sps->name,
                    'status' => $sps->current_status,
                    'uptime_percentage' => $sps->site?->uptime_percentage,
                    'response_time' => $sps->site?->uptimeMonitor?->avg_response_time,
                ]);

            $activeIncidents = $statusPage->activeIncidents()
                ->with('updates')
                ->orderByDesc('started_at')
                ->get();

            $recentIncidents = $statusPage->incidents()
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

            return [
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
            ];
        });
    }

    public static function verifyPassword(StatusPage $statusPage, string $password): bool
    {
        if (! $statusPage->password_hash) {
            return true;
        }

        return Hash::check($password, $statusPage->password_hash);
    }
}

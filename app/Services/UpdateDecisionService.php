<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UpdateDecision;
use App\Enums\UpdateRoute;
use App\Models\SitePlugin;
use App\Models\SiteRiskyPlugin;
use App\Models\UptimeIncident;
use App\Models\VulnerabilityAlert;
use App\Support\Semver;

/**
 * Faza 6 (SPEC §7.2/§7.3) — the heart of smart updates: route an available
 * plugin update onto one of three tracks. PURE and side-effect free — it reads
 * state (AI assessment, vulnerability feed, per-site risk list) and returns an
 * {@see UpdateDecision}; it never writes, dispatches, or calls the network
 * itself (the injected assessor does any AI/HTTP work and is mocked in tests).
 *
 * Rules are evaluated in a FAIL-SAFE order — "the most restrictive outcome
 * wins", and anything the engine cannot positively confirm is treated as
 * unknown and held for approval, never auto-applied:
 *
 *   (a) CriticalBypass — an active *critical* vulnerability for this site+slug
 *       whose fix is reachable by the update (fixed_in null, or <= target).
 *       Security trumps the approval gate.
 *   (b) AwaitApproval  — a major version bump, OR the plugin is on this site's
 *       risky list, OR the AI risk level is {risky, unknown} (including when
 *       assessment failed / threw), OR the site has no recovery path right now
 *       (no backup newer than 24h, or an open uptime incident) — the two
 *       site-level preconditions SPEC §7.2 Rule 1 attaches to auto-apply.
 *   (c) AutoMinor      — everything else: a small, low-risk, non-flagged bump.
 */
class UpdateDecisionService
{
    public function __construct(
        private readonly PluginRiskAssessmentService $assessor,
    ) {}

    public function decide(SitePlugin $plugin): UpdateDecision
    {
        $from = (string) ($plugin->version ?? '');
        $to = (string) ($plugin->update_version ?? '');

        // Assess first so the score is always available for the DTO. Wrapped in
        // fail-safe: any failure means we DON'T know the risk → treat as unknown.
        $score = 50;
        $level = 'unknown';
        $reasons = [];

        try {
            $assessment = $this->assessor->assess($plugin);
            $score = (int) ($assessment['score'] ?? 50);
            $level = (string) ($assessment['level'] ?? 'unknown');
            $reasons = array_values(array_map('strval', (array) ($assessment['reasons'] ?? [])));
        } catch (\Throwable $e) {
            $score = 50;
            $level = 'unknown';
            $reasons = ['Risk assessment unavailable — treated as unknown (fail-safe).'];
        }

        // (a) Critical vulnerability with a reachable fix → bypass approval.
        if ($this->hasReachableCriticalVulnerability($plugin, $to)) {
            return new UpdateDecision(
                UpdateRoute::CriticalBypass,
                $score,
                array_merge($reasons, ['Active critical vulnerability with an available fix — bypassing approval to patch now.']),
            );
        }

        // (b) Hold for approval on any restrictive signal.
        $holdReasons = [];

        if (Semver::isMajorBump($from, $to)) {
            $holdReasons[] = "Major version bump ({$from} → {$to}).";
        }

        if ($this->isFlaggedRisky($plugin)) {
            $holdReasons[] = 'Plugin is on this site\'s risky list.';
        }

        if (in_array($level, ['risky', 'unknown'], true)) {
            $holdReasons[] = "AI risk level is \"{$level}\".";
        }

        foreach ($this->siteRecoveryPreconditions($plugin) as $unmet) {
            $holdReasons[] = $unmet;
        }

        if ($holdReasons !== []) {
            return new UpdateDecision(
                UpdateRoute::AwaitApproval,
                $score,
                array_merge($reasons, $holdReasons),
            );
        }

        // (c) Small, low-risk, non-flagged update → auto-apply.
        return new UpdateDecision(
            UpdateRoute::AutoMinor,
            $score,
            array_merge($reasons, ['Minor/patch update with low assessed risk — safe to auto-apply.']),
        );
    }

    /**
     * Is there an active critical vulnerability for this site+slug whose fix the
     * update actually reaches? A null fixed_in_version means "no published fix
     * yet" — still critical, so we bypass to whatever the update offers.
     */
    private function hasReachableCriticalVulnerability(SitePlugin $plugin, string $to): bool
    {
        return VulnerabilityAlert::query()
            ->active()
            ->where('site_id', $plugin->site_id)
            ->where('software_slug', $plugin->slug)
            ->where('severity', 'critical')
            ->get()
            ->contains(function (VulnerabilityAlert $alert) use ($to): bool {
                if ($alert->fixed_in_version === null) {
                    return true;
                }

                return Semver::atLeast($to, $alert->fixed_in_version);
            });
    }

    /**
     * SPEC §7.2 Rule 1 does not only ask whether the UPDATE is small — it also
     * asks whether the SITE can be recovered if the update goes wrong: a backup
     * newer than 24h must exist, and the site must not already be in an open
     * incident. Either one unmet makes auto-apply irresponsible, so the update
     * is held for approval instead.
     *
     * Fail-safe like the rest of the engine: an unknown last-backup timestamp
     * counts as "no recent backup".
     *
     * @return list<string> unmet preconditions, empty when Rule 1 is satisfied
     */
    private function siteRecoveryPreconditions(SitePlugin $plugin): array
    {
        $site = $plugin->site;

        if ($site === null) {
            return ['Site record unavailable — cannot confirm a recovery path.'];
        }

        $unmet = [];

        $lastBackup = $site->last_backup_at;
        if ($lastBackup === null || $lastBackup->lt(now()->subDay())) {
            $unmet[] = $lastBackup === null
                ? 'No backup on record — Rule 1 requires one newer than 24h.'
                : "Most recent backup is from {$lastBackup->diffForHumans()} — Rule 1 requires one newer than 24h.";
        }

        // Incidents hang off the uptime monitor (uptime_incidents.monitor_id →
        // uptime_monitors.site_id), not off the site directly.
        $openIncidents = UptimeIncident::query()
            ->whereNull('resolved_at')
            ->whereHas('monitor', fn ($q) => $q->where('site_id', $site->id))
            ->count();

        if ($openIncidents > 0) {
            $unmet[] = "Site has {$openIncidents} open uptime incident(s).";
        }

        return $unmet;
    }

    private function isFlaggedRisky(SitePlugin $plugin): bool
    {
        return SiteRiskyPlugin::query()
            ->forSitePlugin($plugin->site_id, $plugin->slug)
            ->where('is_risky', true)
            ->exists();
    }
}

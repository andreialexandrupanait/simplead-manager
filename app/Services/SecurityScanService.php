<?php

namespace App\Services;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class SecurityScanService
{
    private const SEVERITY_PENALTIES = [
        'critical' => 20,
        'high' => 10,
        'medium' => 5,
        'low' => 2,
    ];

    public function scan(Site $site, ?string $trackerKey = null): SecurityScan
    {
        $startTime = microtime(true);

        $issues = $this->runChecks($site, $trackerKey);

        $this->upsertIssues($site, $issues);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 90, 'Calculating security score...');
        }

        $this->markResolvedIssues($site, $issues);

        $counts = $this->calculateScore($site);

        $scan = SecurityScan::create([
            'site_id' => $site->id,
            'score' => $counts['score'],
            'critical_count' => $counts['critical'],
            'high_count' => $counts['high'],
            'medium_count' => $counts['medium'],
            'low_count' => $counts['low'],
            'scan_duration' => (int) (microtime(true) - $startTime),
            'scanned_at' => now(),
        ]);

        SecurityIssue::where('site_id', $site->id)
            ->active()
            ->update(['security_scan_id' => $scan->id]);

        if ($counts['score'] < 50) {
            NotificationService::notifySiteEvent(
                $site,
                'security_score_critical',
                'Security Score Critical',
                "Security score for {$site->name} is {$counts['score']}/100. Immediate attention required.",
                ['Score' => "{$counts['score']}/100", 'Critical Issues' => $counts['critical'], 'High Issues' => $counts['high']],
                'critical'
            );
        }

        ActivityLogger::log(
            'security',
            $counts['score'] < 50 ? 'critical' : ($counts['score'] < 80 ? 'warning' : 'info'),
            "Security scan completed — Score: {$counts['score']}/100",
            "Found {$counts['critical']} critical, {$counts['high']} high, {$counts['medium']} medium, {$counts['low']} low issues.",
            $site,
            ['score' => $counts['score'], 'issues' => $counts['critical'] + $counts['high'] + $counts['medium'] + $counts['low']],
            'shield'
        );

        return $scan;
    }

    public function resolveIssue(SecurityIssue $issue): void
    {
        $issue->update([
            'is_fixed' => true,
            'fixed_at' => now(),
        ]);
    }

    public function ignoreIssue(SecurityIssue $issue): void
    {
        $issue->update([
            'is_ignored' => true,
        ]);
    }

    private function runChecks(Site $site, ?string $trackerKey): array
    {
        $issues = [];

        $issues = array_merge($issues, $this->checkWpVersion($site, $trackerKey));
        $this->checkVulnerabilities($site, $trackerKey);
        $issues = array_merge($issues, $this->checkCoreIntegrity($site, $trackerKey));
        $issues = array_merge($issues, $this->checkDebugMode($site, $trackerKey));
        $issues = array_merge($issues, $this->checkSsl($site, $trackerKey));

        return $issues;
    }

    private function checkWpVersion(Site $site, ?string $trackerKey): array
    {
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 10, 'Checking WordPress version...');
        }

        try {
            $wpVersion = $site->wp_version;
            $recommended = config('security.wordpress.recommended_version', '6.4');
            $minimum = config('security.wordpress.minimum_version', '6.0');

            if ($wpVersion && version_compare($wpVersion, $recommended, '<')) {
                return [[
                    'category' => 'core',
                    'type' => 'wp_outdated',
                    'severity' => version_compare($wpVersion, $minimum, '<') ? 'critical' : 'high',
                    'title' => "WordPress {$wpVersion} is outdated",
                    'description' => 'Running an outdated WordPress version exposes the site to known vulnerabilities.',
                    'recommendation' => 'Update WordPress to the latest version.',
                ]];
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: WP version check failed for site {$site->id}: {$e->getMessage()}");
        }

        return [];
    }

    private function checkVulnerabilities(Site $site, ?string $trackerKey): void
    {
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 30, 'Checking plugin vulnerabilities...');
        }

        try {
            VulnerabilityCheckService::check($site);
        } catch (\Exception $e) {
            Log::warning("Security scan: vulnerability check failed for site {$site->id}: {$e->getMessage()}");
        }
    }

    private function checkCoreIntegrity(Site $site, ?string $trackerKey): array
    {
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 50, 'Checking core integrity...');
        }

        $issues = [];

        try {
            $coreCheck = $site->latestCoreFileCheck;
            if ($coreCheck && $coreCheck->status === 'modified') {
                if ($coreCheck->modified_count > 0) {
                    $issues[] = [
                        'category' => 'core',
                        'type' => 'core_files_modified',
                        'severity' => 'high',
                        'title' => "{$coreCheck->modified_count} core file(s) modified",
                        'description' => 'WordPress core files have been modified from their original versions.',
                        'recommendation' => 'Reinstall WordPress core files or investigate the modifications.',
                    ];
                }
                if ($coreCheck->unknown_count > 0) {
                    $issues[] = [
                        'category' => 'core',
                        'type' => 'core_unknown_files',
                        'severity' => 'medium',
                        'title' => "{$coreCheck->unknown_count} unknown file(s) in core directories",
                        'description' => 'Unknown files were found in WordPress core directories.',
                        'recommendation' => 'Review and remove any suspicious files from core directories.',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: core integrity check failed for site {$site->id}: {$e->getMessage()}");
        }

        return $issues;
    }

    private function checkDebugMode(Site $site, ?string $trackerKey): array
    {
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 65, 'Checking debug mode...');
        }

        try {
            if ($site->is_connected) {
                $api = new WordPressApiService($site);
                $siteInfo = $api->getInfo();
                if (!empty($siteInfo['debug_mode'])) {
                    return [[
                        'category' => 'config',
                        'type' => 'debug_mode_enabled',
                        'severity' => 'high',
                        'title' => 'WordPress debug mode is enabled',
                        'description' => 'WP_DEBUG is enabled in production, which may expose sensitive information.',
                        'recommendation' => 'Disable WP_DEBUG in wp-config.php.',
                    ]];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: debug mode check failed for site {$site->id}: {$e->getMessage()}");
        }

        return [];
    }

    private function checkSsl(Site $site, ?string $trackerKey): array
    {
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 80, 'Checking SSL certificate...');
        }

        try {
            $ssl = $site->sslCertificate;
            if ($ssl) {
                if ($ssl->status === 'expired') {
                    return [[
                        'category' => 'config',
                        'type' => 'ssl_expired',
                        'severity' => 'critical',
                        'title' => 'SSL certificate has expired',
                        'description' => 'The SSL certificate for this site has expired. Visitors will see security warnings.',
                        'recommendation' => 'Renew the SSL certificate immediately.',
                    ]];
                } elseif ($ssl->status === 'expiring_soon') {
                    return [[
                        'category' => 'config',
                        'type' => 'ssl_expiring_soon',
                        'severity' => 'medium',
                        'title' => 'SSL certificate expiring soon',
                        'description' => "The SSL certificate expires in {$ssl->days_remaining} days.",
                        'recommendation' => 'Renew the SSL certificate before it expires.',
                    ]];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: SSL check failed for site {$site->id}: {$e->getMessage()}");
        }

        return [];
    }

    private function upsertIssues(Site $site, array $issues): void
    {
        foreach ($issues as $issueData) {
            $existing = SecurityIssue::where('site_id', $site->id)
                ->where('type', $issueData['type'])
                ->first();

            if ($existing) {
                $existing->update(array_merge($issueData, [
                    'is_fixed' => false,
                ]));
            } else {
                SecurityIssue::create(array_merge($issueData, [
                    'site_id' => $site->id,
                    'first_detected_at' => now(),
                    'is_fixed' => false,
                    'is_ignored' => false,
                ]));
            }
        }
    }

    private function markResolvedIssues(Site $site, array $issues): void
    {
        $currentTypes = array_column($issues, 'type');
        SecurityIssue::where('site_id', $site->id)
            ->active()
            ->whereNotIn('type', $currentTypes)
            ->where('type', 'not like', 'vuln_%')
            ->update(['is_fixed' => true, 'fixed_at' => now()]);
    }

    private function calculateScore(Site $site): array
    {
        $allItems = SecurityIssue::where('site_id', $site->id)->active()->get()
            ->merge($site->vulnerabilityAlerts()->active()->get());

        $score = 100;
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($allItems as $item) {
            if (isset(self::SEVERITY_PENALTIES[$item->severity])) {
                $score -= self::SEVERITY_PENALTIES[$item->severity];
                $counts[$item->severity]++;
            }
        }

        return [
            'score' => max(0, $score),
            'critical' => $counts['critical'],
            'high' => $counts['high'],
            'medium' => $counts['medium'],
            'low' => $counts['low'],
        ];
    }
}

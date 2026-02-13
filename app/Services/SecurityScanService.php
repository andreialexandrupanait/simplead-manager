<?php

namespace App\Services;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class SecurityScanService
{
    public static function scan(Site $site, ?string $trackerKey = null): SecurityScan
    {
        $startTime = microtime(true);
        $issues = [];

        // 1. WP Version check
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 10, 'Checking WordPress version...');
        }
        try {
            $wpVersion = $site->wp_version;
            if ($wpVersion) {
                if (version_compare($wpVersion, '6.4', '<')) {
                    $issues[] = [
                        'category' => 'core',
                        'type' => 'wp_outdated',
                        'severity' => version_compare($wpVersion, '6.0', '<') ? 'critical' : 'high',
                        'title' => "WordPress {$wpVersion} is outdated",
                        'description' => 'Running an outdated WordPress version exposes the site to known vulnerabilities.',
                        'recommendation' => 'Update WordPress to the latest version.',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: WP version check failed for site {$site->id}: {$e->getMessage()}");
        }

        // 2. Vulnerable plugins
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 30, 'Checking plugin vulnerabilities...');
        }
        try {
            VulnerabilityCheckService::check($site);
        } catch (\Exception $e) {
            Log::warning("Security scan: vulnerability check failed for site {$site->id}: {$e->getMessage()}");
        }

        // 3. Core integrity
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 50, 'Checking core integrity...');
        }
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

        // 4. Debug mode check
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 65, 'Checking debug mode...');
        }
        try {
            if ($site->is_connected) {
                $api = new WordPressApiService($site);
                $siteInfo = $api->getInfo();
                if (!empty($siteInfo['debug_mode'])) {
                    $issues[] = [
                        'category' => 'config',
                        'type' => 'debug_mode_enabled',
                        'severity' => 'high',
                        'title' => 'WordPress debug mode is enabled',
                        'description' => 'WP_DEBUG is enabled in production, which may expose sensitive information.',
                        'recommendation' => 'Disable WP_DEBUG in wp-config.php.',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: debug mode check failed for site {$site->id}: {$e->getMessage()}");
        }

        // 5. SSL check
        if ($trackerKey) {
            JobTracker::progress($trackerKey, 80, 'Checking SSL certificate...');
        }
        try {
            $ssl = $site->sslCertificate;
            if ($ssl) {
                if ($ssl->status === 'expired') {
                    $issues[] = [
                        'category' => 'config',
                        'type' => 'ssl_expired',
                        'severity' => 'critical',
                        'title' => 'SSL certificate has expired',
                        'description' => 'The SSL certificate for this site has expired. Visitors will see security warnings.',
                        'recommendation' => 'Renew the SSL certificate immediately.',
                    ];
                } elseif ($ssl->status === 'expiring_soon') {
                    $issues[] = [
                        'category' => 'config',
                        'type' => 'ssl_expiring_soon',
                        'severity' => 'medium',
                        'title' => 'SSL certificate expiring soon',
                        'description' => "The SSL certificate expires in {$ssl->days_remaining} days.",
                        'recommendation' => 'Renew the SSL certificate before it expires.',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Security scan: SSL check failed for site {$site->id}: {$e->getMessage()}");
        }

        // Upsert security issues
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

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 90, 'Calculating security score...');
        }

        // Mark issues not found in this scan as fixed
        $currentTypes = array_column($issues, 'type');
        SecurityIssue::where('site_id', $site->id)
            ->active()
            ->whereNotIn('type', $currentTypes)
            ->where('type', 'not like', 'vuln_%')
            ->update(['is_fixed' => true, 'fixed_at' => now()]);

        // Calculate score
        $activeIssues = SecurityIssue::where('site_id', $site->id)->active()->get();
        $score = 100;
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;

        foreach ($activeIssues as $issue) {
            match ($issue->severity) {
                'critical' => (function () use (&$score, &$criticalCount) { $score -= 20; $criticalCount++; })(),
                'high' => (function () use (&$score, &$highCount) { $score -= 10; $highCount++; })(),
                'medium' => (function () use (&$score, &$mediumCount) { $score -= 5; $mediumCount++; })(),
                'low' => (function () use (&$score, &$lowCount) { $score -= 2; $lowCount++; })(),
                default => null,
            };
        }

        // Also count active vulnerabilities
        $activeVulns = $site->vulnerabilityAlerts()->active()->get();
        foreach ($activeVulns as $vuln) {
            match ($vuln->severity) {
                'critical' => (function () use (&$score, &$criticalCount) { $score -= 20; $criticalCount++; })(),
                'high' => (function () use (&$score, &$highCount) { $score -= 10; $highCount++; })(),
                'medium' => (function () use (&$score, &$mediumCount) { $score -= 5; $mediumCount++; })(),
                'low' => (function () use (&$score, &$lowCount) { $score -= 2; $lowCount++; })(),
                default => null,
            };
        }

        $score = max(0, $score);

        $scanDuration = (int) (microtime(true) - $startTime);

        // Create scan record
        $scan = SecurityScan::create([
            'site_id' => $site->id,
            'score' => $score,
            'critical_count' => $criticalCount,
            'high_count' => $highCount,
            'medium_count' => $mediumCount,
            'low_count' => $lowCount,
            'scan_duration' => $scanDuration,
            'scanned_at' => now(),
        ]);

        // Link active issues to this scan
        SecurityIssue::where('site_id', $site->id)
            ->active()
            ->update(['security_scan_id' => $scan->id]);

        // Notify if critical
        if ($score < 50) {
            NotificationService::notifySiteEvent(
                $site,
                'security_score_critical',
                'Security Score Critical',
                "Security score for {$site->name} is {$score}/100. Immediate attention required.",
                ['Score' => "{$score}/100", 'Critical Issues' => $criticalCount, 'High Issues' => $highCount],
                'critical'
            );
        }

        // Activity log
        ActivityLogger::log(
            'security',
            $score < 50 ? 'critical' : ($score < 80 ? 'warning' : 'info'),
            "Security scan completed — Score: {$score}/100",
            "Found {$criticalCount} critical, {$highCount} high, {$mediumCount} medium, {$lowCount} low issues.",
            $site,
            ['score' => $score, 'issues' => $criticalCount + $highCount + $mediumCount + $lowCount],
            'shield'
        );

        return $scan;
    }

    public static function resolveIssue(SecurityIssue $issue): void
    {
        $issue->update([
            'is_fixed' => true,
            'fixed_at' => now(),
        ]);
    }

    public static function ignoreIssue(SecurityIssue $issue): void
    {
        $issue->update([
            'is_ignored' => true,
        ]);
    }
}

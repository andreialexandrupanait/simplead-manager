<?php

namespace App\Services;

use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SecurityScanService
{
    public static function scan(Site $site): SecurityScan
    {
        $startTime = microtime(true);
        $issues = [];

        try {
            // 1. Check HTTP headers
            $headerIssues = static::checkHeaders($site->url);
            $issues = array_merge($issues, $headerIssues);
        } catch (\Exception $e) {
            Log::warning("Security scan: header check failed for site {$site->id}: {$e->getMessage()}");
        }

        try {
            // 2. Fetch recommendation status from WP connector
            SecurityRecommendationService::check($site);
        } catch (\Exception $e) {
            Log::warning("Security scan: recommendation check failed for site {$site->id}: {$e->getMessage()}");
        }

        try {
            // 3. Check SSL status
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

        try {
            // 4. Check core integrity
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

        try {
            // 5. Check plugin vulnerabilities
            VulnerabilityCheckService::check($site);
        } catch (\Exception $e) {
            Log::warning("Security scan: vulnerability check failed for site {$site->id}: {$e->getMessage()}");
        }

        // 6. Add issues from failed recommendations
        $failedRecs = $site->securityRecommendations()->failed()->get();
        foreach ($failedRecs as $rec) {
            $issues[] = [
                'category' => 'config',
                'type' => 'rec_' . $rec->key,
                'severity' => in_array($rec->category, ['http_headers', 'ssl_https']) ? 'medium' : 'high',
                'title' => $rec->title . ' — not configured',
                'description' => $rec->description,
                'recommendation' => $rec->can_auto_fix ? 'This can be automatically fixed using the Recommendations tab.' : 'Manual configuration required.',
            ];
        }

        // 7. Upsert security issues (preserve first_detected_at and is_ignored)
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

        // Mark issues that were previously detected but not found in this scan as fixed
        $currentTypes = array_column($issues, 'type');
        SecurityIssue::where('site_id', $site->id)
            ->active()
            ->whereNotIn('type', $currentTypes)
            ->where('type', 'not like', 'vuln_%')
            ->update(['is_fixed' => true, 'fixed_at' => now()]);

        // 8. Calculate score
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

        // 9. Calculate category breakdown
        $recommendations = $site->securityRecommendations;
        $categories = ['file_security', 'login_security', 'database_security', 'http_headers', 'ssl_https'];
        $breakdown = [];
        foreach ($categories as $cat) {
            $catRecs = $recommendations->where('category', $cat);
            $total = $catRecs->count();
            $passed = $catRecs->where('status', 'passed')->count();
            $breakdown[$cat] = $total > 0 ? round(($passed / $total) * 100) : 100;
        }

        // Core integrity score
        $coreCheck = $site->latestCoreFileCheck;
        $breakdown['core_integrity'] = ($coreCheck && $coreCheck->status === 'clean') ? 100 : (($coreCheck && $coreCheck->status === 'modified') ? 30 : 50);

        // Plugin vulnerabilities score
        $vulnCount = $site->vulnerabilityAlerts()->active()->count();
        $breakdown['plugins'] = $vulnCount === 0 ? 100 : max(0, 100 - ($vulnCount * 15));

        $scanDuration = (int) (microtime(true) - $startTime);

        // 10. Create scan record
        $scan = SecurityScan::create([
            'site_id' => $site->id,
            'score' => $score,
            'scores_breakdown' => $breakdown,
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

        // 11. Notify if critical
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

        // 12. Activity log
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

    public static function checkHeaders(string $url): array
    {
        $issues = [];

        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'SimpleAD-Manager/1.0 SecurityScanner'])
            ->get($url);

        $headers = collect($response->headers())->mapWithKeys(fn ($v, $k) => [strtolower($k) => $v[0] ?? '']);

        $headerChecks = [
            'x-frame-options' => [
                'type' => 'missing_x_frame_options',
                'title' => 'Missing X-Frame-Options header',
                'description' => 'The X-Frame-Options header is not set, which may allow clickjacking attacks.',
                'recommendation' => 'Add X-Frame-Options: SAMEORIGIN header.',
            ],
            'x-content-type-options' => [
                'type' => 'missing_x_content_type_options',
                'title' => 'Missing X-Content-Type-Options header',
                'description' => 'The X-Content-Type-Options header is not set, which may allow MIME-sniffing attacks.',
                'recommendation' => 'Add X-Content-Type-Options: nosniff header.',
            ],
            'x-xss-protection' => [
                'type' => 'missing_x_xss_protection',
                'title' => 'Missing X-XSS-Protection header',
                'description' => 'The X-XSS-Protection header is not set.',
                'recommendation' => 'Add X-XSS-Protection: 1; mode=block header.',
            ],
            'referrer-policy' => [
                'type' => 'missing_referrer_policy',
                'title' => 'Missing Referrer-Policy header',
                'description' => 'The Referrer-Policy header is not set, which may leak sensitive URL parameters.',
                'recommendation' => 'Add Referrer-Policy: strict-origin-when-cross-origin header.',
            ],
            'permissions-policy' => [
                'type' => 'missing_permissions_policy',
                'title' => 'Missing Permissions-Policy header',
                'description' => 'The Permissions-Policy header is not set.',
                'recommendation' => 'Add a Permissions-Policy header to control browser features.',
            ],
            'content-security-policy' => [
                'type' => 'missing_csp',
                'title' => 'Missing Content-Security-Policy header',
                'description' => 'No Content-Security-Policy header found. CSP is the most effective defense against XSS.',
                'recommendation' => 'Configure a Content-Security-Policy header appropriate for your site.',
            ],
            'strict-transport-security' => [
                'type' => 'missing_hsts',
                'title' => 'Missing HSTS header',
                'description' => 'The Strict-Transport-Security header is not set.',
                'recommendation' => 'Add Strict-Transport-Security: max-age=31536000; includeSubDomains header.',
            ],
        ];

        foreach ($headerChecks as $header => $check) {
            if (!$headers->has($header) || empty($headers[$header])) {
                $issues[] = array_merge($check, [
                    'category' => 'header',
                    'severity' => in_array($header, ['content-security-policy', 'permissions-policy']) ? 'low' : 'medium',
                ]);
            }
        }

        return $issues;
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

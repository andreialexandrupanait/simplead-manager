<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

use App\Models\PerformanceTest;

class CoreWebVitalsCheck
{
    private ?int $siteId = null;

    public function withSiteId(int $siteId): self
    {
        $this->siteId = $siteId;

        return $this;
    }

    public function check(array $connectorData, ?array $gscData = null): array
    {
        if (! $this->siteId) {
            return [];
        }

        $issues = [];

        $mobileTest = PerformanceTest::where('site_id', $this->siteId)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        $desktopTest = PerformanceTest::where('site_id', $this->siteId)
            ->where('status', 'completed')
            ->where('device', 'desktop')
            ->latest('tested_at')
            ->first();

        if (! $mobileTest && ! $desktopTest) {
            $issues[] = [
                'category' => 'core_web_vitals',
                'severity' => 'info',
                'title' => 'No performance test data available',
                'description' => 'Run a performance test to get Core Web Vitals data for SEO assessment.',
                'url' => null,
                'recommendation' => 'Enable performance monitoring to track Core Web Vitals over time.',
                'meta' => null,
            ];

            return $issues;
        }

        // Prioritize mobile (Google uses mobile-first indexing)
        $primary = $mobileTest ?? $desktopTest;
        $device = $mobileTest ? 'Mobile' : 'Desktop';

        $issues = array_merge(
            $issues,
            $this->checkLcp($primary, $device),
            $this->checkCls($primary, $device),
            $this->checkInp($primary, $device),
            $this->checkPerformanceScore($primary, $device),
        );

        return $issues;
    }

    private function checkLcp(PerformanceTest $test, string $device): array
    {
        // Prefer field data over lab data
        $lcp = $test->field_lcp ?? $test->lcp;
        $source = $test->field_lcp !== null ? 'field' : 'lab';

        if ($lcp === null) {
            return [];
        }

        if ($lcp > 4.0) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'high',
                'title' => "{$device}: LCP is poor ({$lcp}s)",
                'description' => "Largest Contentful Paint ({$source}) is {$lcp}s, exceeding the 4.0s poor threshold. This negatively impacts search rankings.",
                'url' => $test->url,
                'recommendation' => 'Optimize images, reduce server response time, and minimize render-blocking resources to improve LCP below 2.5s.',
                'meta' => ['lcp' => $lcp, 'source' => $source, 'device' => strtolower($device)],
            ]];
        }

        if ($lcp > 2.5) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'medium',
                'title' => "{$device}: LCP needs improvement ({$lcp}s)",
                'description' => "Largest Contentful Paint ({$source}) is {$lcp}s, above the 2.5s good threshold.",
                'url' => $test->url,
                'recommendation' => 'Target LCP under 2.5s by optimizing the largest visible element (hero image, heading block).',
                'meta' => ['lcp' => $lcp, 'source' => $source, 'device' => strtolower($device)],
            ]];
        }

        return [];
    }

    private function checkCls(PerformanceTest $test, string $device): array
    {
        $cls = $test->field_cls ?? $test->cls;
        $source = $test->field_cls !== null ? 'field' : 'lab';

        if ($cls === null) {
            return [];
        }

        if ($cls > 0.25) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'high',
                'title' => "{$device}: CLS is poor (".number_format($cls, 3).')',
                'description' => "Cumulative Layout Shift ({$source}) is ".number_format($cls, 3).', exceeding the 0.25 poor threshold.',
                'url' => $test->url,
                'recommendation' => 'Set explicit dimensions on images/ads, avoid inserting content above existing content, and use CSS containment.',
                'meta' => ['cls' => $cls, 'source' => $source, 'device' => strtolower($device)],
            ]];
        }

        if ($cls > 0.1) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'medium',
                'title' => "{$device}: CLS needs improvement (".number_format($cls, 3).')',
                'description' => "Cumulative Layout Shift ({$source}) is ".number_format($cls, 3).', above the 0.1 good threshold.',
                'url' => $test->url,
                'recommendation' => 'Target CLS under 0.1 by ensuring layout stability during page load.',
                'meta' => ['cls' => $cls, 'source' => $source, 'device' => strtolower($device)],
            ]];
        }

        return [];
    }

    private function checkInp(PerformanceTest $test, string $device): array
    {
        $inp = $test->field_inp;

        if ($inp === null) {
            return [];
        }

        if ($inp > 500) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'high',
                'title' => "{$device}: INP is poor ({$inp}ms)",
                'description' => "Interaction to Next Paint is {$inp}ms, exceeding the 500ms poor threshold.",
                'url' => $test->url,
                'recommendation' => 'Reduce JavaScript execution time, break up long tasks, and optimize event handlers.',
                'meta' => ['inp' => $inp, 'device' => strtolower($device)],
            ]];
        }

        if ($inp > 200) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'medium',
                'title' => "{$device}: INP needs improvement ({$inp}ms)",
                'description' => "Interaction to Next Paint is {$inp}ms, above the 200ms good threshold.",
                'url' => $test->url,
                'recommendation' => 'Target INP under 200ms by optimizing input responsiveness.',
                'meta' => ['inp' => $inp, 'device' => strtolower($device)],
            ]];
        }

        return [];
    }

    private function checkPerformanceScore(PerformanceTest $test, string $device): array
    {
        $score = $test->performance_score;

        if ($score === null) {
            return [];
        }

        if ($score < 50) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'high',
                'title' => "{$device}: Lighthouse performance score is poor ({$score}/100)",
                'description' => "The Lighthouse performance score is {$score}, indicating significant performance issues that affect SEO.",
                'url' => $test->url,
                'recommendation' => 'Review Lighthouse opportunities and diagnostics to improve page speed.',
                'meta' => ['score' => $score, 'device' => strtolower($device)],
            ]];
        }

        if ($score < 75) {
            return [[
                'category' => 'core_web_vitals',
                'severity' => 'medium',
                'title' => "{$device}: Lighthouse performance score needs improvement ({$score}/100)",
                'description' => "The Lighthouse performance score is {$score}, below the recommended 90+ threshold.",
                'url' => $test->url,
                'recommendation' => 'Aim for a performance score above 90 for optimal SEO impact.',
                'meta' => ['score' => $score, 'device' => strtolower($device)],
            ]];
        }

        return [];
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PageSpeedService
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.pagespeed.api_key');
    }

    public function analyze(string $url, string $device): array
    {
        $params = [
            'url' => $url,
            'strategy' => strtoupper($device) === 'DESKTOP' ? 'DESKTOP' : 'MOBILE',
            'category' => ['PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO'],
        ];

        if ($this->apiKey) {
            $params['key'] = $this->apiKey;
        }

        $response = Http::timeout(120)->get(self::API_URL, $params);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'PageSpeed API error: ' . ($response->json('error.message') ?? $response->body())
            );
        }

        return $this->parseResults($response->json());
    }

    public function parseResults(array $data): array
    {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits = $lighthouse['audits'] ?? [];

        return [
            'performance_score' => $this->extractScore($categories, 'performance'),
            'accessibility_score' => $this->extractScore($categories, 'accessibility'),
            'best_practices_score' => $this->extractScore($categories, 'best-practices'),
            'seo_score' => $this->extractScore($categories, 'seo'),

            'fcp' => $this->extractMetricSeconds($audits, 'first-contentful-paint'),
            'lcp' => $this->extractMetricSeconds($audits, 'largest-contentful-paint'),
            'cls' => $this->extractMetricRaw($audits, 'cumulative-layout-shift'),
            'tbt' => $this->extractMetricMs($audits, 'total-blocking-time'),
            'si' => $this->extractMetricSeconds($audits, 'speed-index'),
            'tti' => $this->extractMetricSeconds($audits, 'interactive'),

            ...$this->parseFieldData($data),
            ...$this->parsePageStats($lighthouse),

            'opportunities' => $this->parseOpportunities($audits),
            'diagnostics' => $this->parseDiagnostics($audits),
            'lighthouse_version' => $lighthouse['lighthouseVersion'] ?? null,

            'third_party_scripts' => $this->parseThirdPartyScripts($audits),
            ...$this->parseDomSize($audits),
            ...$this->parseUnusedCode($audits),
            'image_audit' => $this->parseImageAudit($audits),
            'filmstrip' => $this->parseFilmstrip($audits),
            'screenshot_final' => $audits['final-screenshot']['details']['data'] ?? null,
        ];
    }

    private function extractScore(array $categories, string $key): ?int
    {
        $score = $categories[$key]['score'] ?? null;
        return $score !== null ? (int) round($score * 100) : null;
    }

    private function extractMetricSeconds(array $audits, string $key): ?float
    {
        $ms = $audits[$key]['numericValue'] ?? null;
        return $ms !== null ? round($ms / 1000, 2) : null;
    }

    private function extractMetricMs(array $audits, string $key): ?float
    {
        return isset($audits[$key]['numericValue'])
            ? round($audits[$key]['numericValue'], 1)
            : null;
    }

    private function extractMetricRaw(array $audits, string $key): ?float
    {
        return isset($audits[$key]['numericValue'])
            ? round($audits[$key]['numericValue'], 4)
            : null;
    }

    private function parseFieldData(array $data): array
    {
        $crux = $data['loadingExperience'] ?? [];
        $metrics = $crux['metrics'] ?? [];

        return [
            'field_fcp' => $this->extractFieldMetric($metrics, 'FIRST_CONTENTFUL_PAINT_MS', 1000),
            'field_lcp' => $this->extractFieldMetric($metrics, 'LARGEST_CONTENTFUL_PAINT_MS', 1000),
            'field_cls' => $this->extractFieldMetric($metrics, 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 1),
            'field_inp' => $this->extractFieldMetric($metrics, 'INTERACTION_TO_NEXT_PAINT', 1),
            'field_ttfb' => $this->extractFieldMetric($metrics, 'EXPERIMENTAL_TIME_TO_FIRST_BYTE', 1),
        ];
    }

    private function extractFieldMetric(array $metrics, string $key, float $divisor): ?float
    {
        $value = $metrics[$key]['percentile'] ?? null;
        if ($value === null) {
            return null;
        }
        return round($value / $divisor, $divisor > 1 ? 2 : 4);
    }

    private function parsePageStats(array $lighthouse): array
    {
        $audits = $lighthouse['audits'] ?? [];
        $diagnostics = $audits['diagnostics']['details']['items'][0] ?? [];
        $resources = $audits['resource-summary']['details']['items'] ?? [];

        $sizes = [
            'html_size' => null,
            'css_size' => null,
            'js_size' => null,
            'image_size' => null,
            'font_size' => null,
        ];

        $typeMap = [
            'document' => 'html_size',
            'stylesheet' => 'css_size',
            'script' => 'js_size',
            'image' => 'image_size',
            'font' => 'font_size',
        ];

        foreach ($resources as $resource) {
            $type = $resource['resourceType'] ?? '';
            if (isset($typeMap[$type])) {
                $sizes[$typeMap[$type]] = $resource['transferSize'] ?? null;
            }
        }

        return [
            'total_requests' => $diagnostics['numRequests'] ?? ($resources ? array_sum(array_column($resources, 'requestCount')) : null),
            'total_size_bytes' => $diagnostics['totalByteWeight'] ?? ($resources ? array_sum(array_column($resources, 'transferSize')) : null),
            ...$sizes,
        ];
    }

    private function parseOpportunities(array $audits): array
    {
        $opportunities = [];

        foreach ($audits as $key => $audit) {
            if (($audit['details']['type'] ?? '') !== 'opportunity') {
                continue;
            }
            if (($audit['score'] ?? 1) >= 0.9) {
                continue;
            }

            $savings = $audit['details']['overallSavingsMs'] ?? 0;
            $opportunities[] = [
                'id' => $key,
                'title' => $audit['title'] ?? $key,
                'description' => $audit['description'] ?? '',
                'savings_ms' => round($savings),
                'savings_bytes' => $audit['details']['overallSavingsBytes'] ?? null,
            ];
        }

        usort($opportunities, fn ($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);

        return array_slice($opportunities, 0, 10);
    }

    private function parseDiagnostics(array $audits): array
    {
        $diagnostics = [];

        foreach ($audits as $key => $audit) {
            if (($audit['details']['type'] ?? '') === 'opportunity') {
                continue;
            }
            if (($audit['score'] ?? 1) >= 0.9) {
                continue;
            }
            if (!isset($audit['title'])) {
                continue;
            }

            $diagnostics[] = [
                'id' => $key,
                'title' => $audit['title'],
                'description' => $audit['description'] ?? '',
                'displayValue' => $audit['displayValue'] ?? null,
            ];
        }

        return array_slice($diagnostics, 0, 10);
    }

    private function parseThirdPartyScripts(array $audits): ?array
    {
        $items = $audits['third-party-summary']['details']['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $scripts = [];
        foreach ($items as $item) {
            $scripts[] = [
                'entity' => $item['entity'] ?? ($item['mainEntity']['text'] ?? 'Unknown'),
                'transfer_size' => $item['transferSize'] ?? 0,
                'blocking_time' => $item['blockingTime'] ?? 0,
                'main_thread_time' => $item['mainThreadTime'] ?? 0,
            ];
        }

        usort($scripts, fn ($a, $b) => $b['blocking_time'] <=> $a['blocking_time']);

        return $scripts;
    }

    private function parseDomSize(array $audits): array
    {
        $items = $audits['dom-size']['details']['items'] ?? [];

        return [
            'dom_elements' => isset($items[0]['value']) ? (int) $items[0]['value'] : null,
            'dom_max_depth' => isset($items[1]['value']) ? (int) $items[1]['value'] : null,
            'dom_max_children' => isset($items[2]['value']) ? (int) $items[2]['value'] : null,
        ];
    }

    private function parseUnusedCode(array $audits): array
    {
        $jsItems = $audits['unused-javascript']['details']['items'] ?? [];
        $cssItems = $audits['unused-css-rules']['details']['items'] ?? [];

        $unusedJsBytes = 0;
        $unusedJsDetails = [];
        foreach ($jsItems as $item) {
            $wasted = $item['wastedBytes'] ?? 0;
            $unusedJsBytes += $wasted;
            $unusedJsDetails[] = [
                'url' => $item['url'] ?? '',
                'total_bytes' => $item['totalBytes'] ?? 0,
                'wasted_bytes' => $wasted,
            ];
        }

        $unusedCssBytes = 0;
        $unusedCssDetails = [];
        foreach ($cssItems as $item) {
            $wasted = $item['wastedBytes'] ?? 0;
            $unusedCssBytes += $wasted;
            $unusedCssDetails[] = [
                'url' => $item['url'] ?? '',
                'total_bytes' => $item['totalBytes'] ?? 0,
                'wasted_bytes' => $wasted,
            ];
        }

        return [
            'unused_js_bytes' => $unusedJsBytes > 0 ? $unusedJsBytes : null,
            'unused_css_bytes' => $unusedCssBytes > 0 ? $unusedCssBytes : null,
            'unused_js_details' => !empty($unusedJsDetails) ? $unusedJsDetails : null,
            'unused_css_details' => !empty($unusedCssDetails) ? $unusedCssDetails : null,
        ];
    }

    private function parseImageAudit(array $audits): ?array
    {
        $checks = [
            'uses-optimized-images' => 'unoptimized',
            'modern-image-formats' => 'not_webp',
            'uses-responsive-images' => 'oversized',
            'offscreen-images' => 'offscreen',
        ];

        $totalSavings = 0;
        $issues = [];
        $counts = [];

        foreach ($checks as $auditKey => $issueType) {
            $items = $audits[$auditKey]['details']['items'] ?? [];
            $counts[$issueType] = count($items);

            foreach ($items as $item) {
                $savings = $item['wastedBytes'] ?? 0;
                $totalSavings += $savings;
                $issues[] = [
                    'type' => $issueType,
                    'url' => $item['url'] ?? '',
                    'wasted_bytes' => $savings,
                ];
            }
        }

        if (empty($issues)) {
            return null;
        }

        return [
            'counts' => $counts,
            'total_savings_bytes' => $totalSavings,
            'issues' => array_slice($issues, 0, 20),
        ];
    }

    private function parseFilmstrip(array $audits): ?array
    {
        $items = $audits['screenshot-thumbnails']['details']['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $filmstrip = [];
        foreach ($items as $item) {
            $filmstrip[] = [
                'timing' => $item['timing'] ?? 0,
                'data' => $item['data'] ?? '',
            ];
        }

        return $filmstrip;
    }
}

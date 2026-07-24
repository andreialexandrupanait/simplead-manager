<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
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
        $response = $this->fetchRaw($url, $device);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'PageSpeed API error: '.($response->json('error.message') ?? $response->body())
            );
        }

        return $this->parseResults($response->json());
    }

    /**
     * The raw PSI HTTP call (endpoint + API key + params), returning the HTTP
     * response for the caller to classify. Shared by analyze() and the audit
     * module's PsiRunner, which needs the raw Lighthouse JSON + status code.
     */
    public function fetchRaw(string $url, string $device): Response
    {
        return Http::timeout(120)->get(self::API_URL, $this->buildParams($url, $device));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(string $url, string $device): array
    {
        $params = [
            'url' => $url,
            'strategy' => strtoupper($device) === 'DESKTOP' ? 'DESKTOP' : 'MOBILE',
            'category' => ['PERFORMANCE'],
        ];

        if ($this->apiKey) {
            $params['key'] = $this->apiKey;
        }

        return $params;
    }

    public function parseResults(array $data): array
    {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits = $lighthouse['audits'] ?? [];

        return [
            'performance_score' => $this->extractScore($categories, 'performance'),

            'fcp' => $this->extractMetricSeconds($audits, 'first-contentful-paint'),
            'lcp' => $this->extractMetricSeconds($audits, 'largest-contentful-paint'),
            'cls' => $this->extractMetricRaw($audits, 'cumulative-layout-shift'),
            'tbt' => $this->extractMetricMs($audits, 'total-blocking-time'),
            'si' => $this->extractMetricSeconds($audits, 'speed-index'),
            'tti' => $this->extractMetricSeconds($audits, 'interactive'),

            ...$this->parseFieldData($data),
            ...$this->parsePageStats($lighthouse),

            'lighthouse_version' => $lighthouse['lighthouseVersion'] ?? null,
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
}

<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class StructuredDataCheck
{
    private const RICH_TYPES = ['FAQPage', 'Product', 'Recipe', 'Event', 'HowTo', 'Review', 'JobPosting', 'Course', 'VideoObject'];

    private const FOUNDATION_TYPES = ['Organization', 'WebSite', 'LocalBusiness'];

    public function check(array $connectorData, ?array $gscData = null): array
    {
        $homepage = $connectorData['homepage'] ?? null;

        if (! $homepage) {
            return [];
        }

        $structuredData = $homepage['structured_data'] ?? [];
        $url = $homepage['url'] ?? null;

        if (empty($structuredData)) {
            return [[
                'category' => 'structured_data',
                'severity' => 'medium',
                'title' => 'No structured data found on homepage',
                'description' => 'The homepage has no JSON-LD structured data. Structured data helps search engines understand page content and can enable rich results.',
                'url' => $url,
                'recommendation' => 'Add JSON-LD structured data to the homepage. At minimum, add Organization or WebSite schema. Consider adding breadcrumbs, FAQ, or other relevant schema types.',
                'meta' => null,
            ]];
        }

        $issues = [];
        $types = [];

        foreach ($structuredData as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['error']) || isset($item['invalid'])) {
                $issues[] = [
                    'category' => 'structured_data',
                    'severity' => 'high',
                    'title' => 'Invalid JSON-LD structured data found',
                    'description' => 'One or more structured data blocks contain invalid JSON-LD that search engines cannot parse.',
                    'url' => $url,
                    'recommendation' => 'Use the Google Rich Results Test to identify and fix invalid structured data.',
                    'meta' => ['error' => $item['error'] ?? $item['invalid'] ?? 'Parse error'],
                ];

                continue;
            }

            $type = $item['@type'] ?? null;
            if ($type) {
                $types[] = $type;
            }
        }

        $hasFoundationType = ! empty(array_intersect($types, self::FOUNDATION_TYPES));
        if (! $hasFoundationType && ! empty($structuredData)) {
            $issues[] = [
                'category' => 'structured_data',
                'severity' => 'low',
                'title' => 'No Organization or WebSite schema found',
                'description' => 'The homepage lacks Organization or WebSite structured data, which helps Google understand your brand and enables the Sitelinks Searchbox feature.',
                'url' => $url,
                'recommendation' => 'Add Organization or WebSite JSON-LD schema to the homepage.',
                'meta' => ['found_types' => $types],
            ];
        }

        $richTypes = array_intersect($types, self::RICH_TYPES);
        if (! empty($richTypes)) {
            $issues[] = [
                'category' => 'structured_data',
                'severity' => 'info',
                'title' => 'Rich result schema types detected: '.implode(', ', $richTypes),
                'description' => 'The homepage uses structured data types that are eligible for rich results in Google Search.',
                'url' => $url,
                'recommendation' => null,
                'meta' => ['rich_types' => array_values($richTypes)],
            ];
        }

        return $issues;
    }
}

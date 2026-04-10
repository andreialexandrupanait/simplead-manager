<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class BrokenLinksCheck
{
    private const MAX_ISSUES = 3;

    public function check(array $connectorData, ?array $gscData = null): array
    {
        $brokenLinks = $connectorData['broken_links'] ?? null;

        if (! $brokenLinks) {
            return [];
        }

        $checked = (int) ($brokenLinks['checked'] ?? 0);

        if ($checked === 0) {
            return [];
        }

        $broken = $brokenLinks['broken'] ?? [];
        $brokenCount = (int) ($brokenLinks['broken_count'] ?? count($broken));

        if ($brokenCount === 0) {
            return [];
        }

        $issues = [];
        $reported = 0;

        foreach ($broken as $link) {
            if ($reported >= self::MAX_ISSUES) {
                break;
            }

            $linkUrl = is_array($link) ? ($link['url'] ?? null) : (string) $link;
            $statusCode = is_array($link) ? ($link['status_code'] ?? null) : null;
            $foundOn = is_array($link) ? ($link['found_on'] ?? null) : null;

            $description = "A broken link was found pointing to \"{$linkUrl}\".";
            if ($statusCode) {
                $description .= " HTTP status: {$statusCode}.";
            }
            if ($foundOn) {
                $description .= " Found on: {$foundOn}.";
            }

            $issues[] = [
                'category' => 'links',
                'severity' => 'high',
                'title' => 'Broken link detected: '.($linkUrl ?? 'unknown URL'),
                'description' => $description,
                'url' => $foundOn ?? $linkUrl,
                'recommendation' => 'Fix or remove the broken link. If the destination has moved, update the URL or add a redirect.',
                'meta' => [
                    'broken_url' => $linkUrl,
                    'status_code' => $statusCode,
                    'found_on' => $foundOn,
                    'total_broken' => $brokenCount,
                ],
            ];

            $reported++;
        }

        if ($brokenCount > self::MAX_ISSUES) {
            $remaining = $brokenCount - self::MAX_ISSUES;
            $issues[] = [
                'category' => 'links',
                'severity' => 'high',
                'title' => "{$remaining} additional broken link(s) not shown",
                'description' => "A total of {$brokenCount} broken links were found. Only the first ".self::MAX_ISSUES.' are shown here.',
                'url' => null,
                'recommendation' => 'Run a full link audit using a dedicated crawler tool to find and fix all broken links.',
                'meta' => ['total_broken' => $brokenCount, 'checked' => $checked],
            ];
        }

        return $issues;
    }
}

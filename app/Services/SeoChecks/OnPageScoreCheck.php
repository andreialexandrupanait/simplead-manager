<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class OnPageScoreCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $homepage = $connectorData['homepage'] ?? null;

        if (! $homepage) {
            return [];
        }

        $issues = [];
        $url = $homepage['url'] ?? null;

        $issues = array_merge($issues, $this->checkWordCount($homepage, $url));
        $issues = array_merge($issues, $this->checkImages($homepage, $url));
        $issues = array_merge($issues, $this->checkLinks($homepage, $url));

        return $issues;
    }

    private function checkWordCount(array $page, ?string $url): array
    {
        $wordCount = (int) ($page['word_count'] ?? 0);

        if ($wordCount < 300) {
            return [[
                'category' => 'content',
                'severity' => 'medium',
                'title' => "Homepage has thin content ({$wordCount} words)",
                'description' => "The homepage contains only {$wordCount} words. Thin content may rank poorly in search results.",
                'url' => $url,
                'recommendation' => 'Expand the homepage content to at least 300 words that clearly communicate your value proposition.',
                'meta' => ['word_count' => $wordCount],
            ]];
        }

        return [];
    }

    private function checkImages(array $page, ?string $url): array
    {
        $issues = [];

        $images = $page['images'] ?? [];
        $imageCount = is_array($images) ? count($images) : (int) ($page['image_count'] ?? 0);

        if ($imageCount === 0) {
            $issues[] = [
                'category' => 'content',
                'severity' => 'low',
                'title' => 'Homepage has no images',
                'description' => 'No images were found on the homepage. Images improve user engagement and can rank in Google Image Search.',
                'url' => $url,
                'recommendation' => 'Add relevant images to the homepage with descriptive alt text.',
                'meta' => null,
            ];

            return $issues;
        }

        if (is_array($images) && $imageCount > 0) {
            $missingAlt = 0;

            foreach ($images as $image) {
                if (is_array($image) && empty($image['alt'])) {
                    $missingAlt++;
                }
            }

            $missingAltRatio = $missingAlt / $imageCount;

            if ($missingAltRatio > 0.5) {
                $missingPct = (int) round($missingAltRatio * 100);
                $issues[] = [
                    'category' => 'content',
                    'severity' => 'medium',
                    'title' => "{$missingPct}% of homepage images are missing alt text",
                    'description' => "{$missingAlt} of {$imageCount} images have no alt attribute. Alt text is important for accessibility and image SEO.",
                    'url' => $url,
                    'recommendation' => 'Add descriptive alt text to all images. Describe what the image shows, not just its file name.',
                    'meta' => ['image_count' => $imageCount, 'missing_alt' => $missingAlt],
                ];
            }
        }

        return $issues;
    }

    private function checkLinks(array $page, ?string $url): array
    {
        $issues = [];

        $internalLinksRaw = $page['internal_links'] ?? [];
        $externalLinksRaw = $page['external_links'] ?? [];
        $internalLinks = (int) ($page['internal_link_count'] ?? (is_array($internalLinksRaw) ? count($internalLinksRaw) : (int) $internalLinksRaw));
        $externalLinks = (int) ($page['external_link_count'] ?? (is_array($externalLinksRaw) ? count($externalLinksRaw) : (int) $externalLinksRaw));

        if ($internalLinks === 0) {
            $issues[] = [
                'category' => 'content',
                'severity' => 'medium',
                'title' => 'Homepage has no internal links',
                'description' => 'No internal links were found on the homepage. Internal linking distributes page authority and helps users navigate.',
                'url' => $url,
                'recommendation' => 'Add internal links to your most important pages (services, blog, about, contact).',
                'meta' => null,
            ];
        }

        if ($externalLinks === 0) {
            $issues[] = [
                'category' => 'content',
                'severity' => 'low',
                'title' => 'Homepage has no external links',
                'description' => 'No outbound links were found on the homepage. Linking to authoritative external sources can improve trust signals.',
                'url' => $url,
                'recommendation' => 'Consider linking to relevant, authoritative external resources where appropriate.',
                'meta' => null,
            ];
        }

        return $issues;
    }
}

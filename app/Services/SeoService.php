<?php

namespace App\Services;

use App\Models\SeoCheck;
use App\Models\Site;

class SeoService
{
    public function fetchAndStore(Site $site): SeoCheck
    {
        $api = new WordPressApiService($site);
        $data = $api->getSeoCheck();

        $score = $this->calculateScore($data);

        return SeoCheck::create([
            'site_id' => $site->id,
            'homepage_title' => $data['homepage_title'] ?? null,
            'homepage_meta_description' => $data['homepage_meta_description'] ?? null,
            'has_sitemap' => $data['has_sitemap'] ?? false,
            'sitemap_url' => $data['sitemap_url'] ?? null,
            'sitemap_pages_count' => $data['sitemap_pages_count'] ?? null,
            'has_robots_txt' => $data['has_robots_txt'] ?? false,
            'robots_txt_issues' => $data['robots_txt_issues'] ?? null,
            'has_og_tags' => $data['has_og_tags'] ?? false,
            'has_twitter_cards' => $data['has_twitter_cards'] ?? false,
            'has_schema_markup' => $data['has_schema_markup'] ?? false,
            'has_canonical' => $data['has_canonical'] ?? false,
            'has_h1' => $data['has_h1'] ?? false,
            'heading_hierarchy_ok' => $data['heading_hierarchy_ok'] ?? false,
            'indexability_issues' => $data['indexability_issues'] ?? null,
            'score' => $score,
            'checked_at' => now(),
        ]);
    }

    public function calculateScore(array $data): int
    {
        $score = 0;

        // Title present + good length (50-60 chars): +10
        $title = $data['homepage_title'] ?? '';
        if (!empty($title)) {
            $len = strlen($title);
            $score += ($len >= 50 && $len <= 60) ? 10 : 5;
        }

        // Description present + good length (150-160 chars): +10
        $desc = $data['homepage_meta_description'] ?? '';
        if (!empty($desc)) {
            $len = strlen($desc);
            $score += ($len >= 150 && $len <= 160) ? 10 : 5;
        }

        // Sitemap exists: +15
        if ($data['has_sitemap'] ?? false) {
            $score += 15;
        }

        // Robots.txt proper: +10
        if ($data['has_robots_txt'] ?? false) {
            $issues = $data['robots_txt_issues'] ?? [];
            $score += empty($issues) ? 10 : 5;
        }

        // OG tags: +10
        if ($data['has_og_tags'] ?? false) {
            $score += 10;
        }

        // Schema markup: +15
        if ($data['has_schema_markup'] ?? false) {
            $score += 15;
        }

        // No indexability issues: +20
        $indexIssues = $data['indexability_issues'] ?? [];
        if (empty($indexIssues)) {
            $score += 20;
        }

        // Headings structure (H1 + hierarchy): +10
        if (($data['has_h1'] ?? false) && ($data['heading_hierarchy_ok'] ?? false)) {
            $score += 10;
        } elseif ($data['has_h1'] ?? false) {
            $score += 5;
        }

        return min(100, $score);
    }

    public function getRecommendations(SeoCheck $check): array
    {
        $recommendations = [];

        if (empty($check->homepage_title)) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Add a title tag',
                'description' => 'Your homepage is missing a title tag. This is critical for SEO ranking.',
            ];
        } elseif (strlen($check->homepage_title) < 50 || strlen($check->homepage_title) > 60) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Optimize title length',
                'description' => 'Your title is ' . strlen($check->homepage_title) . ' characters. Aim for 50-60 characters for best results.',
            ];
        }

        if (empty($check->homepage_meta_description)) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Add a meta description',
                'description' => 'Your homepage is missing a meta description. This impacts click-through rates from search results.',
            ];
        } elseif (strlen($check->homepage_meta_description) < 150 || strlen($check->homepage_meta_description) > 160) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Optimize meta description length',
                'description' => 'Your description is ' . strlen($check->homepage_meta_description) . ' characters. Aim for 150-160 characters.',
            ];
        }

        if (!$check->has_sitemap) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Create an XML sitemap',
                'description' => 'An XML sitemap helps search engines discover and index your pages. Install an SEO plugin to generate one.',
            ];
        }

        if (!$check->has_robots_txt) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Add a robots.txt file',
                'description' => 'A robots.txt file helps control how search engines crawl your site.',
            ];
        } elseif (!empty($check->robots_txt_issues)) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Fix robots.txt issues',
                'description' => 'Your robots.txt has issues that may affect crawling.',
            ];
        }

        if (!$check->has_og_tags) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Add Open Graph tags',
                'description' => 'OG tags improve how your content appears when shared on social media.',
            ];
        }

        if (!$check->has_twitter_cards) {
            $recommendations[] = [
                'priority' => 'low',
                'title' => 'Add Twitter Card tags',
                'description' => 'Twitter Cards improve content appearance when shared on Twitter/X.',
            ];
        }

        if (!$check->has_schema_markup) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Add schema markup',
                'description' => 'Schema markup helps search engines understand your content and enables rich snippets.',
            ];
        }

        if (!$check->has_canonical) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Add canonical URLs',
                'description' => 'Canonical tags prevent duplicate content issues and consolidate link equity.',
            ];
        }

        if (!$check->has_h1) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Add an H1 tag',
                'description' => 'Your homepage is missing an H1 tag. Every page should have exactly one H1.',
            ];
        }

        if (!$check->heading_hierarchy_ok) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Fix heading hierarchy',
                'description' => 'Your heading structure is not properly ordered. Ensure headings follow H1 > H2 > H3 order.',
            ];
        }

        if (!empty($check->indexability_issues)) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Fix indexability issues',
                'description' => 'There are issues preventing your pages from being properly indexed.',
            ];
        }

        return $recommendations;
    }
}

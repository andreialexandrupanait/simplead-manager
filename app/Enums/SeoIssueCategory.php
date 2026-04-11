<?php

declare(strict_types=1);

namespace App\Enums;

enum SeoIssueCategory: string
{
    case MetaTags = 'meta_tags';
    case StructuredData = 'structured_data';
    case Links = 'links';
    case Content = 'content';
    case Technical = 'technical';
    case Indexing = 'indexing';
    case Sitemap = 'sitemap';
    case Robots = 'robots';
    case Performance = 'performance';
    case Plugin = 'plugin';
    case CoreWebVitals = 'core_web_vitals';
    case DuplicateContent = 'duplicate_content';
    case Backlinks = 'backlinks';

    public function label(): string
    {
        return match ($this) {
            self::MetaTags => 'Meta Tags',
            self::StructuredData => 'Structured Data',
            self::Links => 'Links',
            self::Content => 'Content',
            self::Technical => 'Technical',
            self::Indexing => 'Indexing',
            self::Sitemap => 'Sitemap',
            self::Robots => 'Robots.txt',
            self::Performance => 'Performance',
            self::Plugin => 'SEO Plugin',
            self::CoreWebVitals => 'Core Web Vitals',
            self::DuplicateContent => 'Duplicate Content',
            self::Backlinks => 'Backlinks',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::MetaTags => 'tag',
            self::StructuredData => 'code-bracket',
            self::Links => 'link',
            self::Content => 'document-text',
            self::Technical => 'cog-6-tooth',
            self::Indexing => 'magnifying-glass',
            self::Sitemap => 'map',
            self::Robots => 'shield-check',
            self::Performance => 'bolt',
            self::Plugin => 'puzzle-piece',
            self::CoreWebVitals => 'clock',
            self::DuplicateContent => 'document-duplicate',
            self::Backlinks => 'arrow-top-right-on-square',
        };
    }
}

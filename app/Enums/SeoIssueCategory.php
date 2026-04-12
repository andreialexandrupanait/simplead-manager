<?php
declare(strict_types=1);
namespace App\Enums;

enum SeoIssueCategory: string
{
    case Technical = 'technical';
    case OnPage = 'on_page';
    case Performance = 'performance';
    case Links = 'links';
    case Images = 'images';
    case Indexability = 'indexability';
    case Security = 'security';
    case StructuredData = 'structured_data';
    case Social = 'social';
    case Mobile = 'mobile';

    public function label(): string { return match ($this) { self::Technical => 'Technical SEO', self::OnPage => 'On-Page SEO', self::Performance => 'Performance', self::Links => 'Links', self::Images => 'Images & Assets', self::Indexability => 'Indexability', self::Security => 'Security', self::StructuredData => 'Structured Data', self::Social => 'Social Meta', self::Mobile => 'Mobile' }; }
    public function scoringGroup(): string { return match ($this) { self::Technical, self::Indexability => 'technical', self::OnPage, self::Social, self::Mobile => 'on_page', self::Performance => 'performance', default => 'other' }; }
}

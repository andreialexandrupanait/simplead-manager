<?php

declare(strict_types=1);

namespace App\Enums;

enum SeoAuditStatus: string
{
    case Pending = 'pending';
    case Crawling = 'crawling';
    case Analyzing = 'analyzing';
    case Scoring = 'scoring';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending', self::Crawling => 'Crawling', self::Analyzing => 'Analyzing', self::Scoring => 'Scoring', self::Completed => 'Completed', self::Failed => 'Failed'
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray', self::Crawling => 'blue', self::Analyzing => 'indigo', self::Scoring => 'purple', self::Completed => 'green', self::Failed => 'red'
        };
    }

    public function isRunning(): bool
    {
        return in_array($this, [self::Pending, self::Crawling, self::Analyzing, self::Scoring]);
    }
}

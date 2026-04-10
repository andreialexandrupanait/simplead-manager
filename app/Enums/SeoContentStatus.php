<?php

declare(strict_types=1);

namespace App\Enums;

enum SeoContentStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Review = 'review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generating => 'Generating...',
            self::Review => 'In Review',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Generating => 'blue',
            self::Review => 'yellow',
            self::Scheduled => 'purple',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum CoreFileStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Modified = 'modified';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Clean => 'Clean',
            self::Modified => 'Modified',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Clean => 'green',
            self::Modified => 'yellow',
            self::Error => 'red',
            self::Pending => 'gray',
        };
    }
}

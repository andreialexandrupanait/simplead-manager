<?php

declare(strict_types=1);

namespace App\Helpers;

class FormatHelper
{
    public static function bytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), $precision).' '.$units[$i];
    }
}

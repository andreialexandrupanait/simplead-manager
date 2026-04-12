<?php

declare(strict_types=1);

namespace App\Helpers;

class AccentColorHelper
{
    /**
     * Generate a full CSS custom property palette from a single hex color.
     * Returns CSS string with --accent-50 through --accent-950 variables.
     */
    public static function generateCssVariables(string $hex): string
    {
        $rgb = self::hexToRgb($hex);
        $hsl = self::rgbToHsl($rgb[0], $rgb[1], $rgb[2]);

        $shades = [
            50  => [0.97, 0.30],
            100 => [0.94, 0.40],
            200 => [0.87, 0.55],
            300 => [0.77, 0.70],
            400 => [0.63, 0.85],
            500 => [0.50, 1.00],
            600 => [0.42, 1.00],
            700 => [0.35, 0.95],
            800 => [0.29, 0.85],
            900 => [0.24, 0.80],
            950 => [0.16, 0.75],
        ];

        $vars = [];
        foreach ($shades as $shade => $params) {
            [$lightness, $satMult] = $params;
            $s = min(100, $hsl[1] * $satMult);
            $shadeHex = self::hslToHex($hsl[0], $s, $lightness * 100);
            $vars[] = "--accent-{$shade}: {$shadeHex}";
        }

        $r = $rgb[0];
        $g = $rgb[1];
        $b = $rgb[2];
        $vars[] = "--accent-light: rgba({$r}, {$g}, {$b}, 0.2)";

        return implode('; ', $vars) . ';';
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $h = 0;
        $s = 0;

        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            if ($max === $r) {
                $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
            } elseif ($max === $g) {
                $h = (($b - $r) / $d + 2) / 6;
            } else {
                $h = (($r - $g) / $d + 4) / 6;
            }
        }

        return [$h * 360, $s * 100, $l * 100];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $h /= 360;
        $s /= 100;
        $l /= 100;

        if ($s === 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = self::hueToRgb($p, $q, $h + 1 / 3);
            $g = self::hueToRgb($p, $q, $h);
            $b = self::hueToRgb($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Gotenberg\Gotenberg;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScreenshotService
{
    protected string $gotenbergUrl;

    protected Client $httpClient;

    public function __construct()
    {
        $this->gotenbergUrl = config('services.gotenberg.url', 'http://gotenberg:3000');
        $this->httpClient = new Client(['timeout' => 60]);
    }

    /**
     * Capture a screenshot of a URL via Gotenberg's Chromium.
     *
     * @return string|null Binary JPEG data, or null on failure
     */
    public function capture(string $url): ?string
    {
        try {
            $request = Gotenberg::chromium($this->gotenbergUrl)
                ->screenshot()
                ->width(1280)
                ->height(900)
                ->clip()
                ->jpeg()
                ->quality(80)
                ->emulateScreenMediaType()
                ->waitDelay('3s')
                ->url($url);

            $response = Gotenberg::send($request, $this->httpClient);

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            Log::warning("Screenshot capture error for {$url}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Save screenshot binary to storage.
     *
     * @return string|null Storage path
     */
    public function save(string $binary, int $siteId, int $safeUpdateId, string $label): ?string
    {
        $path = "update-screenshots/{$siteId}/{$safeUpdateId}/{$label}.jpg";

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /**
     * Compare two images using GD and return a similarity score (0-100).
     * 100 = identical, 0 = completely different.
     *
     * Uses perceptual hashing with downscaled grayscale comparison.
     */
    public function compare(string $imageBinaryA, string $imageBinaryB): array
    {
        $imgA = @imagecreatefromstring($imageBinaryA);
        $imgB = @imagecreatefromstring($imageBinaryB);

        if (! $imgA || ! $imgB) {
            return ['similarity' => 0, 'diff_percent' => 100, 'error' => 'Could not create images from binary data'];
        }

        // Resize both to a standard comparison size
        $compareWidth = 320;
        $compareHeight = 240;

        $resizedA = imagecreatetruecolor($compareWidth, $compareHeight);
        $resizedB = imagecreatetruecolor($compareWidth, $compareHeight);

        imagecopyresampled($resizedA, $imgA, 0, 0, 0, 0, $compareWidth, $compareHeight, imagesx($imgA), imagesy($imgA));
        imagecopyresampled($resizedB, $imgB, 0, 0, 0, 0, $compareWidth, $compareHeight, imagesx($imgB), imagesy($imgB));

        $totalPixels = $compareWidth * $compareHeight;
        $diffPixels = 0;
        $totalDiff = 0;

        for ($x = 0; $x < $compareWidth; $x++) {
            for ($y = 0; $y < $compareHeight; $y++) {
                $colorA = imagecolorat($resizedA, $x, $y);
                $colorB = imagecolorat($resizedB, $x, $y);

                $rA = ($colorA >> 16) & 0xFF;
                $gA = ($colorA >> 8) & 0xFF;
                $bA = $colorA & 0xFF;

                $rB = ($colorB >> 16) & 0xFF;
                $gB = ($colorB >> 8) & 0xFF;
                $bB = $colorB & 0xFF;

                $pixelDiff = (abs($rA - $rB) + abs($gA - $gB) + abs($bA - $bB)) / (3 * 255);

                $totalDiff += $pixelDiff;

                if ($pixelDiff > 0.1) {
                    $diffPixels++;
                }
            }
        }

        imagedestroy($imgA);
        imagedestroy($imgB);
        imagedestroy($resizedA);
        imagedestroy($resizedB);

        $diffPercent = round(($diffPixels / $totalPixels) * 100, 2);
        $similarity = round(100 - ($totalDiff / $totalPixels * 100), 2);

        return [
            'similarity' => max(0, $similarity),
            'diff_percent' => $diffPercent,
            'diff_pixels' => $diffPixels,
            'total_pixels' => $totalPixels,
        ];
    }

    /**
     * Clean up screenshots for a safe update.
     */
    public function cleanup(int $siteId, int $safeUpdateId): void
    {
        Storage::disk('public')->deleteDirectory("update-screenshots/{$siteId}/{$safeUpdateId}");
    }
}

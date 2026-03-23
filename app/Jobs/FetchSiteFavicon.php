<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FetchSiteFavicon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        $url = rtrim($this->site->url, '/');
        $imageData = null;

        // Strategy 1: Try /favicon.ico
        $imageData = $this->fetchUrl("{$url}/favicon.ico");

        // Strategy 2: Parse homepage HTML for <link rel="icon" ...>
        if (! $imageData) {
            $imageData = $this->fetchFromHtml($url);
        }

        // Strategy 3: Try /apple-touch-icon.png
        if (! $imageData) {
            $imageData = $this->fetchUrl("{$url}/apple-touch-icon.png");
        }

        if (! $imageData) {
            Log::info("FetchSiteFavicon: No favicon found for site {$this->site->id} ({$this->site->domain})");

            return;
        }

        // Normalize to 64x64 PNG
        $png = $this->normalizeToPng($imageData);
        if (! $png) {
            Log::info("FetchSiteFavicon: Failed to normalize favicon for site {$this->site->id}");

            return;
        }

        $path = "favicons/{$this->site->id}.png";
        Storage::disk('public')->put($path, $png);

        $this->site->update(['favicon_path' => $path]);
    }

    private function fetchUrl(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);

            if ($response->successful() && strlen($response->body()) > 0) {
                $contentType = $response->header('Content-Type', '');
                // Accept image types and ICO (which sometimes comes as application/octet-stream)
                if (str_contains($contentType, 'image') || str_contains($contentType, 'octet-stream') || str_contains($contentType, 'x-icon')) {
                    return $response->body();
                }
                // If no content type but body looks like image data, accept it
                $header = substr($response->body(), 0, 8);
                if ($this->looksLikeImage($header)) {
                    return $response->body();
                }
            }
        } catch (\Exception $e) {
            // Silently continue to next strategy
        }

        return null;
    }

    private function fetchFromHtml(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Match <link rel="icon|shortcut icon|apple-touch-icon" href="...">
            if (preg_match_all('/<link[^>]+rel\s*=\s*["\'](?:icon|shortcut icon|apple-touch-icon)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[0] as $tag) {
                    if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/', $tag, $hrefMatch)) {
                        $href = $hrefMatch[1];

                        // Handle relative URLs
                        if (str_starts_with($href, '//')) {
                            $href = 'https:'.$href;
                        } elseif (str_starts_with($href, '/')) {
                            $href = $url.$href;
                        } elseif (! str_starts_with($href, 'http')) {
                            $href = $url.'/'.$href;
                        }

                        // Skip data URIs
                        if (str_starts_with($href, 'data:')) {
                            continue;
                        }

                        $data = $this->fetchUrl($href);
                        if ($data) {
                            return $data;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently continue
        }

        return null;
    }

    private function looksLikeImage(string $header): bool
    {
        // PNG magic bytes
        if (str_starts_with($header, "\x89PNG")) {
            return true;
        }
        // JPEG
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return true;
        }
        // GIF
        if (str_starts_with($header, 'GIF8')) {
            return true;
        }
        // ICO
        if (str_starts_with($header, "\x00\x00\x01\x00") || str_starts_with($header, "\x00\x00\x02\x00")) {
            return true;
        }
        // SVG (starts with < or whitespace then <)
        if (str_contains(substr($header, 0, 5), '<')) {
            return true;
        }

        return false;
    }

    private function normalizeToPng(string $imageData): ?string
    {
        // Try Imagick first (handles ICO natively)
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($imageData);

                // If multi-frame (ICO), pick the largest
                if ($im->getNumberImages() > 1) {
                    $best = null;
                    $bestSize = 0;
                    foreach ($im as $frame) {
                        $size = $frame->getImageWidth() * $frame->getImageHeight();
                        if ($size > $bestSize) {
                            $bestSize = $size;
                            $best = $frame->getImageIndex();
                        }
                    }
                    if ($best !== null) {
                        $im->setIteratorIndex($best);
                    }
                }

                $im->setImageFormat('png');
                $im->resizeImage(64, 64, \Imagick::FILTER_LANCZOS, 1);
                $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

                $result = $im->getImageBlob();
                $im->destroy();

                return $result;
            } catch (\Exception $e) {
                // Fall through to GD
            }
        }

        // GD fallback (doesn't handle ICO natively)
        try {
            $src = @imagecreatefromstring($imageData);
            if (! $src) {
                return null;
            }

            $dst = imagecreatetruecolor(64, 64);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);

            imagecopyresampled($dst, $src, 0, 0, 0, 0, 64, 64, imagesx($src), imagesy($src));

            ob_start();
            imagepng($dst);
            $result = ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }
}

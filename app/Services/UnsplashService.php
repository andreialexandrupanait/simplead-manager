<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnsplashService
{
    private const CACHE_KEY = 'unsplash_slide_images';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Fetch random landscape images from Unsplash for the slideshow.
     *
     * @return array<int, array{url: string, alt: string, author: string, author_url: string}>
     */
    public function getSlideImages(): array
    {
        $accessKey = config('services.unsplash.access_key');

        if (empty($accessKey)) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($accessKey): array {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['Accept-Version' => 'v1'])
                    ->get('https://api.unsplash.com/photos/random', [
                        'client_id' => $accessKey,
                        'count' => 4,
                        'query' => 'business technology',
                        'orientation' => 'landscape',
                    ]);

                if (! $response->successful()) {
                    Log::warning('Unsplash API request failed', [
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                return collect($response->json())
                    ->map(fn (array $photo): array => [
                        'url' => $photo['urls']['regular'] ?? '',
                        'alt' => $photo['alt_description'] ?? 'Background image',
                        'author' => $photo['user']['name'] ?? 'Unknown',
                        'author_url' => $photo['user']['links']['html'] ?? '#',
                    ])
                    ->filter(fn (array $img): bool => $img['url'] !== '')
                    ->values()
                    ->all();
            } catch (\Exception $e) {
                Log::warning('Unsplash API error', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }
}

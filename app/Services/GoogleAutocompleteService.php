<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAutocompleteService
{
    private const SUGGEST_URL = 'https://suggestqueries.google.com/complete/search';

    /**
     * Get autocomplete suggestions for a query.
     *
     * @return string[]
     */
    public function getSuggestions(string $query, string $language = 'ro', string $country = 'ro'): array
    {
        try {
            $response = Http::timeout(10)->get(self::SUGGEST_URL, [
                'client' => 'firefox',
                'q' => $query,
                'hl' => $language,
                'gl' => $country,
            ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();

            return is_array($data[1] ?? null) ? $data[1] : [];
        } catch (\Throwable $e) {
            Log::debug("GoogleAutocomplete: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Expand a seed keyword with a-z suffix suggestions.
     *
     * @return string[]
     */
    public function getExpandedSuggestions(string $seed, string $language = 'ro', string $country = 'ro'): array
    {
        $all = $this->getSuggestions($seed, $language, $country);

        foreach (range('a', 'z') as $letter) {
            $suggestions = $this->getSuggestions("{$seed} {$letter}", $language, $country);
            $all = array_merge($all, $suggestions);

            // Small delay to be polite
            usleep(100_000); // 100ms
        }

        return array_values(array_unique($all));
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class OpenApiService
{
    private const BASE_URL = 'https://api.openapi.ro';

    public function lookupCui(string $cui): ?array
    {
        $cui = $this->normalizeCui($cui);

        if (! preg_match('/^\d{2,10}$/', $cui)) {
            throw new RuntimeException(__('Invalid CUI format.'));
        }

        return Cache::remember("openapi_cui_{$cui}", 86400, function () use ($cui) {
            return $this->fetchFromApi($cui);
        });
    }

    public function testConnection(): bool
    {
        $key = $this->getApiKey();
        $response = Http::withHeaders(['x-api-key' => $key])
            ->timeout(10)
            ->get(self::BASE_URL.'/api/companies/13548146');

        return $response->successful();
    }

    private function fetchFromApi(string $cui): ?array
    {
        $key = $this->getApiKey();

        $executed = RateLimiter::attempt('openapi-lookup', 2, function () {
            // Rate limit: max 2 requests per second
        });

        if (! $executed) {
            throw new RuntimeException(__('Too many requests. Please wait a moment.'));
        }

        $response = Http::withHeaders(['x-api-key' => $key])
            ->timeout(10)
            ->get(self::BASE_URL."/api/companies/{$cui}");

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 403) {
            throw new RuntimeException(__('Invalid OpenAPI.ro API key. Check Settings → Integrations.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('OpenAPI.ro request failed: ').$response->status());
        }

        $data = $response->json();

        return $this->normalizeResponse($data);
    }

    private function normalizeResponse(array $data): array
    {
        $regNumber = $data['numar_reg_com'] ?? null;
        if ($regNumber) {
            $regNumber = $this->formatRegistrationNumber($regNumber);
        }

        return [
            'company' => $data['denumire'] ?? null,
            'address' => $data['adresa'] ?? null,
            'county' => $data['judet'] ?? null,
            'country' => 'Romania',
            'postal_code' => $data['cod_postal'] ?? null,
            'registration_number' => $regNumber,
            'phone' => $data['telefon'] ?? null,
            'vat_payer' => ! empty($data['tva']),
            'company_status' => $data['stare'] ?? null,
            'radiata' => $data['radiata'] ?? false,
        ];
    }

    /**
     * Convert ANAF format "J2000000508324" to standard "J20/508/2024" format.
     */
    private function formatRegistrationNumber(string $raw): string
    {
        // Already formatted (contains /)
        if (str_contains($raw, '/')) {
            return $raw;
        }

        // ANAF format: J{county_2digits}{zeros}{number}{year_last_2digits} or similar
        // Pattern: J + 2-digit county + padded number + year
        if (preg_match('/^([A-Z])(\d{2})0*(\d+?)(\d{4})$/', $raw, $m)) {
            return "{$m[1]}{$m[2]}/{$m[3]}/{$m[4]}";
        }

        return $raw;
    }

    private function normalizeCui(string $cui): string
    {
        $cui = strtoupper(trim($cui));

        if (str_starts_with($cui, 'RO')) {
            $cui = substr($cui, 2);
        }

        return $cui;
    }

    private function getApiKey(): string
    {
        $settings = app(SettingsService::class);
        $encrypted = $settings->get('openapi_key');

        if (! $encrypted) {
            throw new RuntimeException(__('OpenAPI.ro API key not configured. Go to Settings → Integrations.'));
        }

        try {
            return decrypt($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException(__('Failed to decrypt OpenAPI.ro API key.'));
        }
    }
}

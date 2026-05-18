<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostmarkService
{
    private const API_BASE = 'https://api.postmarkapp.com';

    private const CACHE_KEY_DOMAINS = 'postmark.domains';

    private const CACHE_TTL_DOMAINS = 1800;

    public function __construct(
        private SettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->getToken() !== null;
    }

    public function validateToken(string $token): bool
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Postmark-Account-Token' => $token,
            ])->timeout(10)->get(self::API_BASE.'/domains', ['count' => 1, 'offset' => 0]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Postmark token validation failed: '.$e->getMessage());

            return false;
        }
    }

    public function listDomains(): array
    {
        $token = $this->getToken();

        if ($token === null) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY_DOMAINS, self::CACHE_TTL_DOMAINS, function () use ($token) {
            try {
                $allDomains = [];
                $offset = 0;
                $count = 100;

                do {
                    $response = Http::withHeaders([
                        'Accept' => 'application/json',
                        'X-Postmark-Account-Token' => $token,
                    ])->timeout(15)->get(self::API_BASE.'/domains', [
                        'count' => $count,
                        'offset' => $offset,
                    ]);

                    if (! $response->successful()) {
                        Log::warning('Postmark listDomains failed: HTTP '.$response->status());

                        return $allDomains;
                    }

                    $data = $response->json();
                    $domains = $data['Domains'] ?? [];
                    $total = $data['TotalCount'] ?? 0;

                    foreach ($domains as $domain) {
                        $detail = $this->fetchDomainDetail($token, $domain['ID']);
                        if ($detail !== null) {
                            $allDomains[] = $detail;
                        }
                    }

                    $offset += $count;
                } while ($offset < $total);

                return $allDomains;
            } catch (\Throwable $e) {
                Log::warning('Postmark listDomains error: '.$e->getMessage());

                return [];
            }
        });
    }

    public function getDkimSelectorForDomain(string $domain): ?string
    {
        $domain = mb_strtolower(trim($domain));

        foreach ($this->listDomains() as $pmDomain) {
            $name = mb_strtolower($pmDomain['Name'] ?? '');

            if ($name !== $domain) {
                continue;
            }

            $dkimHost = $pmDomain['DKIMHost'] ?? $pmDomain['DKIMPendingHost'] ?? '';

            if ($dkimHost === '') {
                return null;
            }

            $suffix = '._domainkey.'.$domain;

            if (str_ends_with(mb_strtolower($dkimHost), $suffix)) {
                return mb_substr($dkimHost, 0, -mb_strlen($suffix));
            }

            return null;
        }

        return null;
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_DOMAINS);
    }

    private function getToken(): ?string
    {
        $encrypted = $this->settings->get('postmark_account_token');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $token = decrypt($encrypted);
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function fetchDomainDetail(string $token, int $domainId): ?array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Postmark-Account-Token' => $token,
            ])->timeout(10)->get(self::API_BASE.'/domains/'.$domainId);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning("Postmark fetchDomainDetail({$domainId}) error: ".$e->getMessage());

            return null;
        }
    }
}

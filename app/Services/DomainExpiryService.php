<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DomainStatus;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Domain-registration expiry via RDAP (the JSON successor to WHOIS). rdap.org
 * bootstraps to the authoritative registry for each TLD.
 *
 * No public-suffix library is available, so we resolve the registrable domain
 * by walking up from the host one label at a time until a registry answers —
 * this handles both subdomains (www.example.com) and multi-part TLDs
 * (example.co.uk) without a PSL.
 */
class DomainExpiryService
{
    /** Alert threshold: flag as expiring within this many days. */
    public const EXPIRING_SOON_DAYS = 30;

    /**
     * @return array{status: DomainStatus, expires_at: ?Carbon, registrar: ?string, error: ?string}
     */
    public static function check(Site $site): array
    {
        $host = parse_url($site->url, PHP_URL_HOST) ?: $site->url;
        $host = Str::of($host)->lower()->ltrim()->rtrim()->replaceFirst('www.', '')->toString();

        if ($host === '' || ! str_contains($host, '.')) {
            return self::result(DomainStatus::Error, null, null, 'Could not derive a domain from the site URL.');
        }

        $labels = explode('.', $host);
        // Walk up: full host, then drop the leftmost label, down to the last two.
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            try {
                $response = Http::timeout(15)
                    ->withHeaders(['Accept' => 'application/rdap+json'])
                    ->get("https://rdap.org/domain/{$candidate}");
            } catch (\Throwable $e) {
                return self::result(DomainStatus::Error, null, null, "RDAP request failed: {$e->getMessage()}");
            }

            if ($response->status() === 404) {
                continue; // not the registered domain — try the parent
            }

            if (! $response->successful()) {
                return self::result(DomainStatus::Error, null, null, "RDAP returned HTTP {$response->status()} for {$candidate}.");
            }

            return self::parse($response->json());
        }

        return self::result(DomainStatus::Error, null, null, "No RDAP registry recognised {$host}.");
    }

    /**
     * @return array{status: DomainStatus, expires_at: ?Carbon, registrar: ?string, error: ?string}
     */
    private static function parse(mixed $data): array
    {
        if (! is_array($data)) {
            return self::result(DomainStatus::Error, null, null, 'Malformed RDAP response.');
        }

        $expiresAt = null;
        foreach ($data['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? null) === 'expiration' && ! empty($event['eventDate'])) {
                try {
                    $expiresAt = Carbon::parse($event['eventDate']);
                } catch (\Throwable) {
                    // ignore an unparseable date
                }
                break;
            }
        }

        $registrar = null;
        foreach ($data['entities'] ?? [] as $entity) {
            if (in_array('registrar', $entity['roles'] ?? [], true)) {
                foreach ($entity['vcardArray'][1] ?? [] as $field) {
                    if (($field[0] ?? null) === 'fn') {
                        $registrar = is_string($field[3] ?? null) ? $field[3] : null;
                        break 2;
                    }
                }
            }
        }

        if (! $expiresAt) {
            return self::result(DomainStatus::Error, null, $registrar, 'RDAP response had no expiration date.');
        }

        // Carbon 3 diffs are signed; expiry is in the future here (isPast handled
        // above), so measure now → expiry to get the days remaining.
        $daysRemaining = now()->diffInDays($expiresAt);
        $status = match (true) {
            $expiresAt->isPast() => DomainStatus::Expired,
            $daysRemaining <= self::EXPIRING_SOON_DAYS => DomainStatus::ExpiringSoon,
            default => DomainStatus::Active,
        };

        return self::result($status, $expiresAt, $registrar, null);
    }

    /**
     * @return array{status: DomainStatus, expires_at: ?Carbon, registrar: ?string, error: ?string}
     */
    private static function result(DomainStatus $status, ?Carbon $expiresAt, ?string $registrar, ?string $error): array
    {
        return ['status' => $status, 'expires_at' => $expiresAt, 'registrar' => $registrar, 'error' => $error];
    }
}

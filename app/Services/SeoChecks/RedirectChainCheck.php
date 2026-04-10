<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class RedirectChainCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $redirects = $connectorData['redirects'] ?? null;

        if (! $redirects) {
            return [];
        }

        $issues = [];

        $chain = $redirects['chain'] ?? [];
        $chainLength = (int) ($redirects['chain_length'] ?? count($chain));
        $hasMixedSsl = (bool) ($redirects['has_mixed_ssl'] ?? false);

        if ($hasMixedSsl) {
            $issues[] = [
                'category' => 'technical',
                'severity' => 'critical',
                'title' => 'Mixed HTTP/HTTPS in redirect chain',
                'description' => 'The redirect chain switches between HTTP and HTTPS protocols. This causes security warnings and can harm rankings.',
                'url' => $chain[0] ?? null,
                'recommendation' => 'Ensure all redirects consistently use HTTPS. Update internal links and canonical URLs to use HTTPS throughout.',
                'meta' => ['chain' => $chain, 'chain_length' => $chainLength],
            ];
        }

        if ($chainLength > 2) {
            $issues[] = [
                'category' => 'technical',
                'severity' => 'high',
                'title' => "Redirect chain too long ({$chainLength} hops)",
                'description' => "The redirect chain has {$chainLength} hops, which wastes crawl budget and increases page load time.",
                'url' => $chain[0] ?? null,
                'recommendation' => 'Consolidate the redirect chain to a single direct redirect from the original URL to the final destination.',
                'meta' => ['chain' => $chain, 'chain_length' => $chainLength],
            ];
        } elseif ($chainLength === 1 || $chainLength === 2) {
            $issues[] = [
                'category' => 'technical',
                'severity' => 'info',
                'title' => "Redirect in place ({$chainLength} hop(s))",
                'description' => 'A redirect chain of acceptable length was detected.',
                'url' => $chain[0] ?? null,
                'recommendation' => null,
                'meta' => ['chain' => $chain, 'chain_length' => $chainLength],
            ];
        }

        foreach ($redirects['issues'] ?? [] as $connectorIssue) {
            $issues[] = [
                'category' => 'technical',
                'severity' => 'medium',
                'title' => 'Redirect issue detected',
                'description' => is_string($connectorIssue) ? $connectorIssue : ($connectorIssue['message'] ?? 'A redirect issue was detected.'),
                'url' => null,
                'recommendation' => 'Review and resolve the redirect issue to maintain crawl efficiency.',
                'meta' => is_array($connectorIssue) ? $connectorIssue : null,
            ];
        }

        return $issues;
    }
}

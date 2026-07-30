<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\CloudflareService;

/**
 * Pilot operation: purge a site's entire Cloudflare edge cache.
 *
 * A single synchronous API call — Instant duration. It is irreversible (you
 * cannot "un-purge" a cache) so rollback() is never invoked; verify() is a
 * no-op because purge_everything is confirmed by the API call itself.
 */
class PurgeCloudflareCacheOperation implements Operation
{
    public function key(): string
    {
        return 'cloudflare.purge';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Instant;
    }

    public function isReversible(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $cf = $context->site->loadMissing('siteCloudflare')->siteCloudflare;

        if ($cf === null || ! $cf->is_active || empty($cf->zone_id)) {
            return OperationResult::skipped(
                $context->site->id,
                'Site has no active Cloudflare zone.',
            );
        }

        return OperationResult::success($context->site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $cf = $context->site->loadMissing('siteCloudflare.cloudflareConnection')->siteCloudflare;

        if ($cf === null || $cf->cloudflareConnection === null) {
            return OperationResult::failure($context->site->id, 'Cloudflare connection is missing.');
        }

        // Matches the codebase convention (SiteCloudflare Livewire, ValidateConnection):
        // the service is constructed with the site's specific connection.
        $service = new CloudflareService($cf->cloudflareConnection);

        $purged = $service->purgeEverything($cf->zone_id);

        return $purged
            ? OperationResult::success($context->site->id, 'Cloudflare cache purged.')
            : OperationResult::failure($context->site->id, 'Cloudflare reported the purge did not succeed.');
    }

    public function verify(OperationContext $context): OperationResult
    {
        // purge_everything is synchronous: a successful execute() already means
        // the edge cache was purged, so there is nothing further to confirm.
        return OperationResult::verified($context->site->id, 'Purge is synchronous — no further check needed.');
    }

    public function rollback(OperationContext $context): OperationResult
    {
        // Never invoked (isReversible() === false); a purge cannot be undone.
        return OperationResult::skipped($context->site->id, 'A cache purge is irreversible.');
    }
}

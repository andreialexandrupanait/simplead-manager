<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\SitePresetService;

/**
 * SPEC §6.2 / §13 — apply the curated "Standard SimpleAD" preset package.
 *
 * Unlike the update and backup operations, this one is genuinely synchronous:
 * the service writes the tweak rows and pushes them to the connector in-band, so
 * the full prepare → execute → verify lifecycle carries real meaning here and
 * verify() can confirm the settings actually landed.
 */
class ApplySitePresetOperation implements Operation
{
    public function __construct(
        private readonly SitePresetService $presets,
    ) {}

    public function key(): string
    {
        return 'presets.apply';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Medium;
    }

    public function isReversible(): bool
    {
        // Overwriting a tweak loses its previous value — there is nothing to
        // restore it from.
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        if (! $context->site->is_connected) {
            return OperationResult::skipped($context->site->id, 'Site is not connected — presets cannot be pushed.');
        }

        return OperationResult::success($context->site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $result = $this->presets->applyStandardPackage($context->site);

        if ($result['applied'] === 0) {
            return OperationResult::failure($context->site->id, 'No preset setting was applied.');
        }

        return OperationResult::success(
            $context->site->id,
            "{$result['applied']} setting(s) applied".($result['woo'] ? ' (incl. the WooCommerce group).' : '.'),
            ['applied' => $result['applied'], 'woo' => $result['woo']],
        );
    }

    public function verify(OperationContext $context): OperationResult
    {
        // The package's non-negotiable setting: WordPress must not update itself
        // behind Manager's back (§13, "Esențială"). If that one is on, the push
        // reached the site.
        $applied = \App\Models\SecuritySetting::query()
            ->where('site_id', $context->site->id)
            ->where('setting_key', 'disable_all_updates')
            ->where('is_enabled', true)
            ->exists();

        return $applied
            ? OperationResult::verified($context->site->id, 'Automatic WordPress updates are disabled — the package took hold.')
            : OperationResult::failure($context->site->id, 'The essential "disable automatic updates" setting is not active after the push.');
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'Previous tweak values are not retained, so presets cannot be un-applied.');
    }
}

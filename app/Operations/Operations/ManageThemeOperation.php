<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Models\SiteTheme;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\PluginManagerService;

/**
 * SPEC §6.2 — "Gestionare teme" across a selection.
 *
 * Params: `slug` and `action` ∈ {activate, delete}. There is no deactivate for a
 * theme in WordPress — activating another one is how you deactivate the current.
 */
class ManageThemeOperation implements Operation
{
    private const ACTIONS = ['activate', 'delete'];

    public function __construct(
        private readonly PluginManagerService $themes,
    ) {}

    public function key(): string
    {
        return 'themes.manage';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Short;
    }

    public function isReversible(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        // Switching the active theme changes what every visitor sees.
        return true;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $site = $context->site;

        if (! in_array($this->action($context), self::ACTIONS, true)) {
            return OperationResult::failure($site->id, 'Unknown theme action.');
        }

        if (! $site->is_connected) {
            return OperationResult::skipped($site->id, 'Site is not connected.');
        }

        $theme = $this->theme($context);

        if ($theme === null) {
            return OperationResult::skipped($site->id, "Theme \"{$this->slug($context)}\" is not installed here.");
        }

        if ($this->action($context) === 'activate' && $theme->is_active) {
            return OperationResult::skipped($site->id, 'Already the active theme.');
        }

        if ($this->action($context) === 'delete' && $theme->is_active) {
            return OperationResult::skipped($site->id, 'Refusing to delete the active theme.');
        }

        return OperationResult::success($site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $theme = $this->theme($context);

        if ($theme === null) {
            return OperationResult::failure($context->site->id, 'Theme vanished between prepare and execute.');
        }

        $result = match ($this->action($context)) {
            'activate' => $this->themes->activateTheme($context->site, $theme->id),
            'delete' => $this->themes->deleteTheme($context->site, $theme->id),
            default => ['success' => false, 'message' => 'Unknown theme action.'],
        };

        return $result['success']
            ? OperationResult::success($context->site->id, (string) $result['message'])
            : OperationResult::failure($context->site->id, (string) $result['message']);
    }

    public function verify(OperationContext $context): OperationResult
    {
        $theme = $this->theme($context);

        $ok = match ($this->action($context)) {
            'activate' => $theme !== null && $theme->is_active,
            'delete' => $theme === null,
            default => false,
        };

        return $ok
            ? OperationResult::verified($context->site->id, 'Local inventory reflects the change.')
            : OperationResult::failure($context->site->id, 'Local inventory does not reflect the change.');
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'Re-activate the previous theme explicitly.');
    }

    private function theme(OperationContext $context): ?SiteTheme
    {
        return SiteTheme::query()
            ->where('site_id', $context->site->id)
            ->where('slug', $this->slug($context))
            ->first();
    }

    private function slug(OperationContext $context): string
    {
        return (string) ($context->params['slug'] ?? '');
    }

    private function action(OperationContext $context): string
    {
        return (string) ($context->params['action'] ?? '');
    }
}

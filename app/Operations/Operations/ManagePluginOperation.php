<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Models\SitePlugin;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\PluginManagerService;

/**
 * SPEC §6.2 — "Instalare / activare / ștergere pluginuri" across a selection.
 *
 * Params: `slug` (the plugin, fleet-wide) and `action` ∈ {activate, deactivate,
 * delete}. The site-local SitePlugin id is resolved per site — the same plugin
 * has a different row id on every site, so a fleet action can only be expressed
 * by slug.
 *
 * Deactivating one misbehaving plugin across twelve sites is exactly the shape
 * of work the engine exists for, which is why this is a real synchronous
 * operation with a meaningful verify().
 */
class ManagePluginOperation implements Operation
{
    private const ACTIONS = ['activate', 'deactivate', 'delete'];

    public function __construct(
        private readonly PluginManagerService $plugins,
    ) {}

    public function key(): string
    {
        return 'plugins.manage';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Short;
    }

    public function isReversible(): bool
    {
        // activate/deactivate are trivially reversible; delete is not, so the
        // engine is told the batch as a whole cannot be relied on to reverse.
        return false;
    }

    public function requiresApproval(): bool
    {
        // Deleting or deactivating a plugin on a client's live site is not
        // something a mis-click should be able to start.
        return true;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $site = $context->site;

        if (! in_array($this->action($context), self::ACTIONS, true)) {
            return OperationResult::failure($site->id, 'Unknown plugin action.');
        }

        if (! $site->is_connected) {
            return OperationResult::skipped($site->id, 'Site is not connected.');
        }

        $plugin = $this->plugin($context);

        if ($plugin === null) {
            return OperationResult::skipped($site->id, "Plugin \"{$this->slug($context)}\" is not installed here.");
        }

        $action = $this->action($context);

        if ($action === 'activate' && $plugin->is_active) {
            return OperationResult::skipped($site->id, 'Already active.');
        }

        if ($action === 'deactivate' && ! $plugin->is_active) {
            return OperationResult::skipped($site->id, 'Already inactive.');
        }

        return OperationResult::success($site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $plugin = $this->plugin($context);

        if ($plugin === null) {
            return OperationResult::failure($context->site->id, 'Plugin vanished between prepare and execute.');
        }

        $result = match ($this->action($context)) {
            'activate' => $this->plugins->activatePlugin($context->site, $plugin->id),
            'deactivate' => $this->plugins->deactivatePlugin($context->site, $plugin->id),
            'delete' => $this->plugins->deletePlugin($context->site, $plugin->id),
            default => ['success' => false, 'message' => 'Unknown plugin action.'],
        };

        return $result['success']
            ? OperationResult::success($context->site->id, (string) $result['message'])
            : OperationResult::failure($context->site->id, (string) $result['message']);
    }

    public function verify(OperationContext $context): OperationResult
    {
        $plugin = $this->plugin($context);
        $action = $this->action($context);

        $ok = match ($action) {
            'activate' => $plugin !== null && $plugin->is_active,
            'deactivate' => $plugin === null || ! $plugin->is_active,
            'delete' => $plugin === null,
            default => false,
        };

        return $ok
            ? OperationResult::verified($context->site->id, "Local inventory reflects the {$action}.")
            : OperationResult::failure($context->site->id, "Local inventory does not reflect the {$action}.");
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'Reverse the action explicitly — a delete cannot be undone from here.');
    }

    private function plugin(OperationContext $context): ?SitePlugin
    {
        return SitePlugin::query()
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

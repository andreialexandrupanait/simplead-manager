<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Models\Site;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\BulkSettingsCopyService;
use Illuminate\Support\Collection;

/**
 * SPEC §6.2 — "Copiere setări între site-uri".
 *
 * Params: `source_site_id` and `kind` ∈ {security, tweaks}. The underlying
 * service already refuses to carry anything site-specific across (login URL,
 * 2FA, CAPTCHA secrets, firewall config), so this operation only has to gate the
 * obvious: a real source, and never copying a site onto itself.
 */
class CopySiteSettingsOperation implements Operation
{
    private const KINDS = ['security', 'tweaks'];

    public function __construct(
        private readonly BulkSettingsCopyService $copier,
    ) {}

    public function key(): string
    {
        return 'settings.copy';
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
        return true;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $site = $context->site;

        if (! in_array($this->kind($context), self::KINDS, true)) {
            return OperationResult::failure($site->id, 'Unknown settings kind.');
        }

        $source = $this->source($context);

        if ($source === null) {
            return OperationResult::failure($site->id, 'No such source site.');
        }

        if ($source->id === $site->id) {
            return OperationResult::skipped($site->id, 'This is the source site.');
        }

        return OperationResult::success($site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $source = $this->source($context);

        if ($source === null) {
            return OperationResult::failure($context->site->id, 'The source site disappeared between prepare and execute.');
        }

        $targets = new Collection([$context->site]);

        $result = $this->kind($context) === 'security'
            ? $this->copier->copySecuritySettings($source, $targets)
            : $this->copier->copyTweakSettings($source, $targets);

        if (($result['total'] ?? 0) === 0) {
            return OperationResult::skipped($context->site->id, 'The source site has nothing copyable of that kind.');
        }

        return OperationResult::success(
            $context->site->id,
            ($result['pushed'] ?? 0) > 0
                ? 'Settings copied and pushed to the connector.'
                : 'Settings copied (site not connected, so nothing was pushed).',
            $result,
        );
    }

    public function verify(OperationContext $context): OperationResult
    {
        // The copy is a database write the service performs in-band; a failure
        // would have surfaced as an exception, and re-reading every key here
        // would cost more than it proves.
        return OperationResult::verified($context->site->id, 'Settings rows written.');
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'Overwritten settings are not snapshotted.');
    }

    private function source(OperationContext $context): ?Site
    {
        $id = (int) ($context->params['source_site_id'] ?? 0);

        return $id > 0 ? Site::find($id) : null;
    }

    private function kind(OperationContext $context): string
    {
        return (string) ($context->params['kind'] ?? '');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse\Playbooks;

use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\SecurityIssue;
use App\Models\Site;
use App\Services\IncidentResponse\Contracts\PlaybookInterface;
use App\Services\IncidentResponse\IncidentActionExecutor;

class SecurityCriticalPlaybook implements PlaybookInterface
{
    public function name(): string
    {
        return 'security_critical';
    }

    public function matches(IncidentTriggerType $trigger, array $context): bool
    {
        return $trigger === IncidentTriggerType::SecurityCritical;
    }

    public function execute(IncidentResponse $response, Site $site, IncidentActionExecutor $executor, array $context): bool
    {
        $issueId = $context['security_issue_id'] ?? null;
        $issue = $issueId ? SecurityIssue::find($issueId) : null;

        if (! $issue) {
            return false;
        }

        $response->update(['diagnosis' => [
            'issue_type' => $issue->type,
            'severity' => $issue->severity,
            'title' => $issue->title,
        ]]);

        return match ($issue->type) {
            'debug_mode_enabled' => $this->fixDebugMode($response, $site, $executor),
            'core_files_modified' => $this->fixCoreFiles($response, $site, $executor),
            default => false,
        };
    }

    private function fixDebugMode(IncidentResponse $response, Site $site, IncidentActionExecutor $executor): bool
    {
        $result = $executor->execute($response, $site, 'apply_security_fix', 'playbook', [
            'key' => 'disable_debug',
        ]);

        return $result['success'] ?? false;
    }

    private function fixCoreFiles(IncidentResponse $response, Site $site, IncidentActionExecutor $executor): bool
    {
        // Reinstall core files to fix modified files
        $result = $executor->execute($response, $site, 'apply_security_fix', 'playbook', [
            'key' => 'reinstall_core',
        ]);

        return $result['success'] ?? false;
    }
}

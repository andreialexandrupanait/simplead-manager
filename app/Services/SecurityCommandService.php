<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use App\Models\SecurityCommand;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SecurityCommandService
{
    public function getPendingCommands(Site $site): Collection
    {
        return SecurityCommand::where('site_id', $site->id)
            ->where('status', SecurityCommandStatus::Pending)
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderBy('created_at')
            ->get();
    }

    public function createCommand(
        Site $site,
        string $category,
        string $action,
        array $payload = [],
        SecurityCommandPriority $priority = SecurityCommandPriority::Normal,
    ): SecurityCommand {
        // Cancel any existing pending commands for the same site/category/action
        SecurityCommand::where('site_id', $site->id)
            ->where('category', $category)
            ->where('action', $action)
            ->where('status', SecurityCommandStatus::Pending)
            ->update(['status' => SecurityCommandStatus::Cancelled]);

        return SecurityCommand::create([
            'site_id' => $site->id,
            'category' => $category,
            'action' => $action,
            'payload' => $payload,
            'priority' => $priority,
        ]);
    }

    public function processCommandResult(SecurityCommand $command, array $result): void
    {
        $success = $result['success'] ?? false;

        if ($success) {
            $command->markCompleted($result);

            // Update corresponding setting
            SecuritySetting::where('site_id', $command->site_id)
                ->where('category', $command->category)
                ->where('setting_key', $command->action)
                ->update([
                    'applied_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);
        } else {
            $command->markFailed($result);

            if (! $command->shouldRetry()) {
                SecuritySetting::where('site_id', $command->site_id)
                    ->where('category', $command->category)
                    ->where('setting_key', $command->action)
                    ->update([
                        'failed_at' => now(),
                        'failure_reason' => $result['error'] ?? 'Command failed',
                    ]);

                if ($command->priority === SecurityCommandPriority::Critical) {
                    Log::critical('Security command failed', [
                        'command_id' => $command->id,
                        'site_id' => $command->site_id,
                        'action' => $command->action,
                        'error' => $result['error'] ?? 'Unknown',
                    ]);
                }
            }
        }

        // Recalculate site score
        $site = $command->site;
        $site->update([
            'security_hardening_score' => app(SecuritySettingsService::class)->getSecurityScore($site),
        ]);
    }

    public function cleanupStaleCommands(): int
    {
        $staleCommands = SecurityCommand::stale()->get();
        $count = 0;

        foreach ($staleCommands as $command) {
            if ($command->shouldRetry()) {
                $command->status = SecurityCommandStatus::Pending;
                $command->picked_up_at = null;
                $command->save();
            } else {
                $command->status = SecurityCommandStatus::Failed;
                $command->completed_at = now();
                $command->result = ['error' => 'Command timed out after being picked up'];
                $command->save();
            }
            $count++;
        }

        return $count;
    }
}

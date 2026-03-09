<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\SecurityActivityService;
use App\Services\SecurityCommandService;
use App\Services\SecuritySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityAgentController extends Controller
{
    public function __construct(
        protected SecurityCommandService $commandService,
        protected SecuritySettingsService $settingsService,
        protected SecurityActivityService $activityService,
    ) {}

    public function pendingCommands(Request $request, Site $site): JsonResponse
    {
        $commands = DB::transaction(function () use ($site) {
            $commands = $this->commandService->getPendingCommands($site);

            foreach ($commands as $command) {
                $command->markPickedUp();
            }

            return $commands;
        });

        return response()->json([
            'commands' => $commands->map(fn ($cmd) => [
                'id' => $cmd->id,
                'category' => $cmd->category,
                'action' => $cmd->action,
                'payload' => $cmd->payload,
                'priority' => $cmd->priority->value,
            ]),
        ]);
    }

    public function commandResults(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'results' => 'required|array|max:50',
            'results.*.command_id' => 'required|integer',
            'results.*.success' => 'required|boolean',
            'results.*.error' => 'nullable|string|max:1000',
            'results.*.data' => 'nullable|array',
        ]);

        $processed = 0;

        foreach ($validated['results'] as $result) {
            $command = $site->securityCommands()->find($result['command_id']);
            if (!$command) {
                continue;
            }

            $this->commandService->processCommandResult($command, $result);
            $processed++;
        }

        return response()->json(['processed' => $processed]);
    }

    public function activityLogs(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'logs' => 'required|array|max:1000',
            'logs.*.event_type' => 'required|string|max:50',
            'logs.*.username' => 'nullable|string|max:255',
            'logs.*.object_type' => 'nullable|string|max:50',
            'logs.*.object_name' => 'nullable|string|max:255',
            'logs.*.action' => 'nullable|string|max:100',
            'logs.*.ip_address' => 'nullable|ip',
            'logs.*.user_agent' => 'nullable|string|max:500',
            'logs.*.details' => 'nullable|array|max:50',
            'logs.*.occurred_at' => 'nullable|date',
        ]);

        $ingested = $this->activityService->ingestLogs($site, $validated['logs']);

        return response()->json(['ingested' => $ingested]);
    }

    public function syncState(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array|max:200',
            'settings.*.category' => 'required|string|max:50',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.applied' => 'nullable|boolean',
            'settings.*.failed' => 'nullable|boolean',
            'settings.*.reason' => 'nullable|string|max:1000',
        ]);

        $this->settingsService->syncSettingsFromAgent($site, $validated['settings']);

        return response()->json(['synced' => true]);
    }
}

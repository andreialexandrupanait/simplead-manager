<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivityLogsRequest;
use App\Http\Requests\Api\CommandResultsRequest;
use App\Http\Requests\Api\SyncStateRequest;
use App\Http\Resources\SecurityCommandResource;
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
            'commands' => SecurityCommandResource::collection($commands),
        ]);
    }

    public function commandResults(CommandResultsRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

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

    public function activityLogs(ActivityLogsRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

        $ingested = $this->activityService->ingestLogs($site, $validated['logs']);

        return response()->json(['ingested' => $ingested]);
    }

    public function syncState(SyncStateRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

        $this->settingsService->syncSettingsFromAgent($site, $validated['settings']);

        return response()->json(['synced' => true]);
    }
}

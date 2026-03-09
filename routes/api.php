<?php

use App\Http\Controllers\Api\SecurityAgentController;
use Illuminate\Support\Facades\Route;

// Agent API — authenticated via HMAC signature
Route::prefix('agent/{site_token}/security')
    ->middleware(['agent.auth', 'throttle:agent'])
    ->group(function () {
        Route::get('/pending-commands', [SecurityAgentController::class, 'pendingCommands']);
        Route::post('/command-results', [SecurityAgentController::class, 'commandResults']);
        Route::post('/activity-logs', [SecurityAgentController::class, 'activityLogs'])
            ->middleware('throttle:agent-activity-logs');
        Route::post('/sync-state', [SecurityAgentController::class, 'syncState']);
    });

<?php

use App\Http\Controllers\Api\SecurityAgentController;
use Illuminate\Support\Facades\Route;

// User API — authenticated via personal access token (Bearer token)
Route::prefix('v1')
    ->middleware(['api.token', 'throttle:60,1'])
    ->group(function () {
        Route::get('/me', fn () => response()->json([
            'user' => [
                'id' => auth()->id(),
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
                'role' => auth()->user()->role->value,
            ],
        ]));

        Route::get('/sites', function () {
            $sites = auth()->user()->isAdmin()
                ? \App\Models\Site::select('id', 'name', 'url', 'status', 'health_score', 'is_up', 'is_connected', 'last_synced_at')->get()
                : auth()->user()->sites()->select('id', 'name', 'url', 'status', 'health_score', 'is_up', 'is_connected', 'last_synced_at')->get();

            return response()->json(['sites' => $sites]);
        });
    });

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

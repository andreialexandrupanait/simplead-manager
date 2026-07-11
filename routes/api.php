<?php

use App\Http\Controllers\Api\BackupCallbackController;
use Illuminate\Support\Facades\Route;

// Backup progress callback from WP plugin during direct-upload. Auth is via HMAC
// token in X-Backup-Token header (validated inside controller against backup row).
Route::post('/backup/callback', BackupCallbackController::class)
    ->middleware('throttle:120,1')
    ->name('api.backup.callback');

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

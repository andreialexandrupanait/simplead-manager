<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Backup\V2\Support\BackupV2Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for the V2 backup console (P5).
 *
 * When config('backup_v2.ui_enabled') is false the whole prefix behaves as if it
 * does not exist (404) — the console must be invisible in production, not merely
 * forbidden. When the flag is on, access is still restricted to admins (403).
 */
final class EnsureBackupV2Ui
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! BackupV2Access::uiEnabled()) {
            // Invisible, not forbidden: pretend the route does not exist.
            abort(404);
        }

        if (! BackupV2Access::allows($request->user())) {
            abort(403, 'The backup V2 console is restricted to administrators.');
        }

        return $next($request);
    }
}

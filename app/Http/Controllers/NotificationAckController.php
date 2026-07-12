<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P1-23: acknowledgement must not mutate on GET. Slack/Discord/email link-preview
 * crawlers fetch embedded URLs, which previously auto-acknowledged alerts and
 * permanently suppressed escalation. GET now renders a confirmation page; a POST
 * (CSRF-protected, initiated by a human clicking the button) performs the actual
 * acknowledgement. Existing GET ack links stay valid — they land on the confirm
 * page instead of silently acking.
 */
class NotificationAckController extends Controller
{
    /**
     * GET — render the confirmation page. No mutation.
     */
    public function show(string $token): Response
    {
        $log = NotificationLog::where('ack_token', $token)->firstOrFail();

        return response()->view('notifications.acknowledge-confirm', [
            'token' => $token,
            'event' => $log->event,
            'site' => $log->site?->name,
            'alreadyAcknowledged' => $log->acknowledged_at !== null,
        ]);
    }

    /**
     * POST — perform the acknowledgement.
     */
    public function confirm(Request $request, string $token): Response|RedirectResponse
    {
        $log = NotificationLog::where('ack_token', $token)
            ->whereNull('acknowledged_at')
            ->first();

        // Already acknowledged (or an invalid token) — show the confirm page,
        // which reports the current state rather than erroring.
        if ($log === null) {
            return redirect()->route('notifications.ack', $token);
        }

        $log->update(['acknowledged_at' => now()]);

        return response()->view('notifications.acknowledged', [
            'event' => $log->event,
            'site' => $log->site?->name,
        ]);
    }
}

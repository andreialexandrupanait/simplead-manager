<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NotificationLog;

class NotificationAckController extends Controller
{
    public function __invoke(string $token)
    {
        $log = NotificationLog::where('ack_token', $token)
            ->whereNull('acknowledged_at')
            ->firstOrFail();

        $log->update(['acknowledged_at' => now()]);

        return response()->view('notifications.acknowledged', [
            'event' => $log->event,
            'site' => $log->site?->name,
        ]);
    }
}

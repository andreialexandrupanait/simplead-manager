<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $source = $request->header('X-Webhook-Source', $request->get('source', 'unknown'));

        Log::info("Webhook received from {$source}", ['payload_keys' => array_keys($payload)]);

        ActivityLogger::log(
            type: 'webhook',
            severity: 'info',
            title: "Webhook received from {$source}",
            description: json_encode(array_slice($payload, 0, 5)),
            metadata: ['source' => $source, 'payload_size' => strlen(json_encode($payload))],
        );

        return response()->json(['received' => true]);
    }
}

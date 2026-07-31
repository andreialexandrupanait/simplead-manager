<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FormCheck;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §5.4's "verificare de livrare" — the half that was impossible until now.
 *
 * The connector (2.21.0+) redirects the site's own form notification to a SimpleAD
 * test mailbox, marked [TEST]. That mailbox forwards the message here, and its
 * arrival is the proof that the form actually delivers mail. Before 2.21.0 the
 * connector aborted the send, so there was never a message to look for and the
 * delivery_verified column could not be written by anything.
 *
 * A forwarding rule was chosen over polling IMAP: it needs no mail library, no
 * stored mailbox password, and no scheduled poll — the message arriving IS the
 * event.
 */
class FormTestDeliveryController extends Controller
{
    /**
     * How recently the test must have been submitted for an arriving message to
     * count. Without this a replayed or archived email could verify a form that has
     * been broken for months.
     */
    private const MAX_AGE_HOURS = 24;

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('monitoring.form_test.webhook_secret', '');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Form-Test-Secret'))) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        // Forwarders differ in what they call the parts of a message, so read the
        // whole thing and look for the marker rather than trusting one field.
        $haystack = implode(' ', array_filter([
            $request->input('subject'),
            $request->input('text'),
            $request->input('body'),
            $request->input('html'),
            $request->input('to'),
        ], 'is_string'));

        $host = $this->markedHost($haystack);

        if ($host === null) {
            return response()->json(['matched' => false, 'reason' => 'no marker'], 202);
        }

        $site = Site::query()
            ->where('url', 'ilike', '%'.$host.'%')
            ->first();

        if (! $site) {
            Log::info('Form-test delivery: no site matches the marked host', ['host' => $host]);

            return response()->json(['matched' => false, 'reason' => 'unknown host'], 202);
        }

        $check = FormCheck::where('site_id', $site->id)->first();

        if (! $check?->submitted_at || $check->submitted_at->lt(now()->subHours(self::MAX_AGE_HOURS))) {
            return response()->json(['matched' => false, 'reason' => 'no recent submission'], 202);
        }

        $check->update([
            'delivery_verified' => true,
            'delivery_verified_at' => now(),
        ]);

        Log::info('Form-test delivery verified', ['site_id' => $site->id, 'host' => $host]);

        return response()->json(['matched' => true, 'site_id' => $site->id]);
    }

    /**
     * Pull the host out of the connector's marked address, sam+samtest@{host}.
     */
    private function markedHost(string $haystack): ?string
    {
        if (preg_match('/sam\+samtest@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/i', $haystack, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}

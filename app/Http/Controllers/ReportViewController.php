<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;

class ReportViewController extends Controller
{
    public function __invoke(Report $report, string $token)
    {
        // Constant-time comparison so the token can't be recovered by timing.
        // Nulling view_token revokes the link (falls through to 404 below).
        if (! $report->view_token || ! hash_equals($report->view_token, $token)) {
            abort(404);
        }

        if (! $report->data_snapshot) {
            abort(404);
        }

        $client = $report->site?->client;

        return view('client-portal.report', [
            'client' => $client,
            'report' => $report,
            'isPublicView' => true,
        ]);
    }
}

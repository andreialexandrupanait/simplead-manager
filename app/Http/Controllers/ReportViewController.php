<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;

class ReportViewController extends Controller
{
    public function __invoke(Report $report, string $token)
    {
        if (! $report->view_token || $token !== $report->view_token) {
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

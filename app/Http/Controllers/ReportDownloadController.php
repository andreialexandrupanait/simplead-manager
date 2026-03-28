<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, Report $report)
    {
        // For authenticated routes, verify site ownership
        if ($request->routeIs('reports.download')) {
            $site = $report->site;
            if (! $site || (! $request->user()->isAdmin() && $site->user_id !== $request->user()->id)) {
                abort(403, 'Unauthorized.');
            }
        }

        if (! $report->file_path) {
            abort(404, 'Report file not available.');
        }

        $filePath = Storage::disk('local')->path($report->file_path);

        if (! file_exists($filePath)) {
            abort(404, 'Report file not found.');
        }

        $cacheHeaders = [
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"report-'.$report->id.'-'.($report->generated_at->timestamp ?? 0).'"',
        ];

        // Preview mode: display inline in browser
        if ($request->query('preview')) {
            return response()->file($filePath, array_merge([
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.($report->file_name ?? 'report.pdf').'"',
            ], $cacheHeaders));
        }

        return response()->download($filePath, $report->file_name ?? 'report.pdf', $cacheHeaders);
    }
}

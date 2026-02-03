<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, Report $report)
    {
        if (!$report->file_path) {
            abort(404, 'Report file not available.');
        }

        $filePath = Storage::disk('local')->path($report->file_path);

        if (!file_exists($filePath)) {
            abort(404, 'Report file not found.');
        }

        // Preview mode: display inline in browser
        if ($request->query('preview')) {
            return response()->file($filePath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . ($report->file_name ?? 'report.pdf') . '"',
            ]);
        }

        return response()->download($filePath, $report->file_name ?? 'report.pdf');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BulkReportDownloadController extends Controller
{
    public function __invoke(Request $request, Site $site)
    {
        abort_unless($site->user_id === $request->user()->id, 403);

        $ids = array_filter(explode(',', $request->query('ids', '')));
        abort_if(empty($ids), 404);

        $reports = $site->reports()
            ->whereIn('id', $ids)
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->get();

        abort_if($reports->isEmpty(), 404);

        $zipPath = storage_path('app/temp/reports-' . uniqid() . '.zip');

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::CREATE) === true, 500);

        foreach ($reports as $report) {
            $filePath = Storage::disk('local')->path($report->file_path);
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $report->file_name ?? 'report-' . $report->id . '.pdf');
            }
        }

        $zip->close();

        $zipName = 'reports-' . $site->name . '-' . now()->format('Y-m-d') . '.zip';

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BulkReportDownloadController extends Controller
{
    public function __invoke(Request $request, Site $site)
    {
        // Single canonical rule (matches the single-report download path): a user
        // may download iff they may access the site — admins always, cross-tenant
        // never. The previous owner-only check wrongly blocked admins and
        // client-assigned users.
        abort_unless($request->user()->canAccessSite($site), 403);

        $ids = array_filter(explode(',', $request->query('ids', '')));
        abort_if(empty($ids), 404);

        $reports = $site->reports()
            ->whereIn('id', $ids)
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->get();

        abort_if($reports->isEmpty(), 404);

        // Ensure the temp directory exists — it is not tracked in git and may be
        // absent on a fresh deploy/CI checkout, in which case ZipArchive can
        // neither create the archive nor its own temp file (bulk download 500s).
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $zipPath = $tempDir.'/reports-'.uniqid().'.zip';

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::CREATE) === true, 500);

        foreach ($reports as $report) {
            /** @var Report $report */
            $filePath = Storage::disk('local')->path($report->file_path);
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $report->file_name ?? 'report-'.$report->id.'.pdf');
            }
        }

        $zip->close();

        $zipName = 'reports-'.$site->name.'-'.now()->format('Y-m-d').'.zip';

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }
}

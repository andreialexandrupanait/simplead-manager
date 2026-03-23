<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->header('X-Backup-Token');
        $backupId = $request->input('backup_id');

        if (! $token || ! $backupId) {
            return response()->json(['error' => 'Missing token or backup_id'], 400);
        }

        $backup = Backup::find($backupId);
        if (! $backup) {
            return response()->json(['error' => 'Backup not found'], 404);
        }

        $expectedToken = self::generateToken($backup);
        if (! hash_equals($expectedToken, $token)) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

        $partsDone = (int) $request->input('parts_done', 0);
        $partsTotal = (int) $request->input('parts_total', 1);
        $strategy = $request->input('strategy', 'unknown');

        // Map upload progress to 30-90% range (prepare=0-30%, upload=30-90%, finalize=90-100%)
        $uploadPercent = $partsTotal > 0 ? ($partsDone / $partsTotal) : 0;
        $overallPercent = 30 + (int) ($uploadPercent * 60);

        $message = $strategy === 's3_multipart'
            ? "Uploading to S3... part {$partsDone}/{$partsTotal}"
            : "Uploading... chunk {$partsDone}/{$partsTotal}";

        $backup->update([
            'stage' => 'uploading',
            'progress_percent' => min($overallPercent, 90),
            'progress_message' => $message,
        ]);

        return response()->json(['success' => true]);
    }

    public static function generateToken(Backup $backup): string
    {
        return hash_hmac('sha256', $backup->id.'|'.$backup->created_at, config('app.key'));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\Backup\Storage\DropboxDriver;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BackupRelayController extends Controller
{
    public function __invoke(Request $request, int $backupId): JsonResponse
    {
        $token = $request->header('X-Backup-Token');

        if (! $token) {
            return response()->json(['error' => 'Missing token'], 400);
        }

        $backup = Backup::find($backupId);
        if (! $backup) {
            return response()->json(['error' => 'Backup not found'], 404);
        }

        $expectedToken = BackupCallbackController::generateToken($backup);
        if (! hash_equals($expectedToken, $token)) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

        $offset = (int) $request->header('X-Chunk-Offset', 0);
        $isLast = $request->header('X-Chunk-Is-Last') === '1';
        $chunkData = $request->getContent();

        $cacheKey = "backup-relay:{$backupId}";
        $context = Cache::get($cacheKey);

        if (! $context) {
            return response()->json(['error' => 'No relay context found. Was the backup initiated?'], 404);
        }

        try {
            if ($context['strategy'] === 'dropbox') {
                $this->relayToDropbox($backup, $context, $chunkData, $offset, $isLast, $cacheKey);
            } elseif ($context['strategy'] === 'local') {
                $this->relayToLocal($context, $chunkData, $isLast, $cacheKey);
            } else {
                return response()->json(['error' => 'Unknown relay strategy'], 400);
            }
        } catch (\Throwable $e) {
            Log::error("Backup relay failed for backup {$backupId}", [
                'offset' => $offset,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Relay failed: '.$e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'is_last' => $isLast]);
    }

    protected function relayToDropbox(Backup $backup, array $context, string $data, int $offset, bool $isLast, string $cacheKey): void
    {
        $destination = $backup->storageDestination;
        $driver = StorageFactory::make($destination);

        if (! $driver instanceof DropboxDriver) {
            throw new \RuntimeException('Expected DropboxDriver for Dropbox relay');
        }

        if ($offset === 0) {
            // Start upload session
            $sessionId = $driver->startUploadSession($data);
            Cache::put($cacheKey, array_merge($context, [
                'session_id' => $sessionId,
                'offset' => strlen($data),
            ]), 14400);
        } elseif ($isLast) {
            // Finish session
            $driver->finishUploadSession(
                $context['session_id'],
                $context['offset'],
                $data,
                $context['remote_path']
            );
            Cache::forget($cacheKey);
        } else {
            // Append to session
            $driver->appendToUploadSession(
                $context['session_id'],
                $context['offset'],
                $data
            );
            Cache::put($cacheKey, array_merge($context, [
                'offset' => $context['offset'] + strlen($data),
            ]), 14400);
        }
    }

    protected function relayToLocal(array $context, string $data, bool $isLast, string $cacheKey): void
    {
        $filePath = $context['file_path'];
        $dir = dirname($filePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Append chunk to target file
        $fh = fopen($filePath, 'ab');
        if (! $fh) {
            throw new \RuntimeException("Cannot open file for writing: {$filePath}");
        }
        fwrite($fh, $data);
        fclose($fh);

        if ($isLast) {
            Cache::forget($cacheKey);
        }
    }
}

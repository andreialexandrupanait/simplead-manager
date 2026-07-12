<?php

declare(strict_types=1);

namespace App\Services\Backup\Storage;

use App\Models\StorageDestination;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

class DropboxDriver implements StorageDriver
{
    protected array $config;

    protected string $basePath;

    protected const CHUNK_SIZE = 8 * 1024 * 1024; // 8MB

    protected const LARGE_FILE_THRESHOLD = 8 * 1024 * 1024; // 8MB — match chunk size to avoid loading large files into memory

    protected const MAX_TRANSIENT_RETRIES = 5; // per request: retry 429/5xx so one throttled chunk can't abort a multi-GB upload (P1-64)

    public function __construct(
        protected StorageDestination $destination
    ) {
        $this->config = $destination->config ?? [];
        $this->basePath = rtrim($this->config['base_path'] ?? '/#1 SAD Workspace/4. Backup', '/');
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $fileSize = filesize($localPath);
        $fullPath = $this->fullPath($remotePath);

        if ($fileSize > self::LARGE_FILE_THRESHOLD) {
            $this->uploadChunked($localPath, $fullPath);
        } else {
            $this->uploadSimple($localPath, $fullPath);
        }
    }

    public function uploadToAbsolutePath(string $localPath, string $absoluteDropboxPath): void
    {
        $fileSize = filesize($localPath);
        $path = '/'.ltrim($absoluteDropboxPath, '/');

        if ($fileSize > self::LARGE_FILE_THRESHOLD) {
            $this->uploadChunked($localPath, $path);
        } else {
            $this->uploadSimple($localPath, $path);
        }
    }

    protected function uploadSimple(string $localPath, string $dropboxPath): void
    {
        $this->apiRequest('https://content.dropboxapi.com/2/files/upload', [
            'headers' => [
                'Dropbox-API-Arg' => json_encode([
                    'path' => $dropboxPath,
                    'mode' => 'overwrite',
                    'autorename' => false,
                ]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => file_get_contents($localPath),
        ]);
    }

    public function startUploadSession(string $data): string
    {
        $response = $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/start', [
            'headers' => [
                'Dropbox-API-Arg' => json_encode(['close' => false]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $data,
        ]);

        return $response['session_id'];
    }

    public function appendToUploadSession(string $sessionId, int $offset, string $data): void
    {
        $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/append_v2', [
            'headers' => [
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => [
                        'session_id' => $sessionId,
                        'offset' => $offset,
                    ],
                    'close' => false,
                ]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $data,
        ]);
    }

    public function finishUploadSession(string $sessionId, int $offset, string $data, string $remotePath): void
    {
        $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/finish', [
            'headers' => [
                'Dropbox-API-Arg' => json_encode([
                    'cursor' => [
                        'session_id' => $sessionId,
                        'offset' => $offset,
                    ],
                    'commit' => [
                        'path' => $remotePath,
                        'mode' => 'overwrite',
                        'autorename' => false,
                    ],
                ]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $data,
        ]);
    }

    protected function uploadChunked(string $localPath, string $dropboxPath): void
    {
        $handle = fopen($localPath, 'rb');
        $fileSize = filesize($localPath);
        $offset = 0;

        // Start session
        $chunk = fread($handle, self::CHUNK_SIZE);
        $response = $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/start', [
            'headers' => [
                'Dropbox-API-Arg' => json_encode(['close' => false]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $chunk,
        ]);
        $sessionId = $response['session_id'];
        $offset += strlen($chunk);

        // Append chunks
        while ($offset < $fileSize) {
            $remaining = $fileSize - $offset;
            $chunkSize = min(self::CHUNK_SIZE, $remaining);
            $chunk = fread($handle, $chunkSize);
            $isLast = ($offset + strlen($chunk)) >= $fileSize;

            if ($isLast) {
                // Finish session
                $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/finish', [
                    'headers' => [
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => [
                                'session_id' => $sessionId,
                                'offset' => $offset,
                            ],
                            'commit' => [
                                'path' => $dropboxPath,
                                'mode' => 'overwrite',
                                'autorename' => false,
                            ],
                        ]),
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $chunk,
                ]);
            } else {
                $this->apiRequest('https://content.dropboxapi.com/2/files/upload_session/append_v2', [
                    'headers' => [
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => [
                                'session_id' => $sessionId,
                                'offset' => $offset,
                            ],
                            'close' => false,
                        ]),
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $chunk,
                ]);
            }

            $offset += strlen($chunk);
        }

        fclose($handle);
    }

    public function download(string $remotePath, string $localPath): void
    {
        $fullPath = $this->fullPath($remotePath);
        $accessToken = $this->getAccessToken();

        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $response = $this->makeDownloadRequest($fullPath, $localPath, $accessToken);

        // If token expired, refresh and retry once
        if ($response->status() === 401) {
            $accessToken = $this->refreshAccessToken();
            $response = $this->makeDownloadRequest($fullPath, $localPath, $accessToken);
        }

        if ($response->failed()) {
            throw new RuntimeException('Dropbox download failed: '.$response->body());
        }
    }

    protected function makeDownloadRequest(string $dropboxPath, string $localPath, string $accessToken): \Illuminate\Http\Client\Response
    {
        $request = Http::withToken($accessToken)->timeout(600);

        $headers = [
            'Dropbox-API-Arg' => json_encode(['path' => $dropboxPath]),
        ];

        $teamMemberId = $this->config['team_member_id'] ?? null;
        if ($teamMemberId) {
            $headers['Dropbox-API-Select-User'] = $teamMemberId;
        }

        $rootNamespaceId = $this->config['root_namespace_id'] ?? null;
        if ($rootNamespaceId) {
            $headers['Dropbox-API-Path-Root'] = json_encode([
                '.tag' => 'root',
                'root' => $rootNamespaceId,
            ]);
        }

        return $request->withHeaders($headers)
            ->sink($localPath)
            ->withBody('', 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/download');
    }

    public function delete(string $remotePath): void
    {
        $this->apiRequest('https://api.dropboxapi.com/2/files/delete_v2', [
            'json' => ['path' => $this->fullPath($remotePath)],
        ]);
    }

    public function exists(string $remotePath): bool
    {
        try {
            $this->apiRequest('https://api.dropboxapi.com/2/files/get_metadata', [
                'json' => ['path' => $this->fullPath($remotePath)],
            ]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function size(string $remotePath): int
    {
        $response = $this->apiRequest('https://api.dropboxapi.com/2/files/get_metadata', [
            'json' => ['path' => $this->fullPath($remotePath)],
        ]);

        return $response['size'] ?? 0;
    }

    public function list(string $directory = ''): array
    {
        $path = $directory ? $this->fullPath($directory) : $this->basePath;

        $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder', [
            'json' => ['path' => $path],
        ]);

        $files = [];
        foreach ($response['entries'] ?? [] as $entry) {
            $files[] = [
                'name' => $entry['name'],
                'path' => $entry['path_display'],
                'size' => $entry['size'] ?? 0,
                'is_dir' => $entry['.tag'] === 'folder',
                'modified_at' => $entry['server_modified'] ?? null,
            ];
        }

        while (! empty($response['has_more'])) {
            $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder/continue', [
                'json' => ['cursor' => $response['cursor']],
            ]);

            foreach ($response['entries'] ?? [] as $entry) {
                $files[] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'],
                    'size' => $entry['size'] ?? 0,
                    'is_dir' => $entry['.tag'] === 'folder',
                    'modified_at' => $entry['server_modified'] ?? null,
                ];
            }
        }

        return $files;
    }

    public function listRecursive(string $directory = ''): array
    {
        $path = $directory ? $this->fullPath($directory) : $this->basePath;

        $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder', [
            'json' => ['path' => $path, 'recursive' => true],
        ]);

        $files = [];
        $append = function ($entries) use (&$files) {
            foreach ($entries as $entry) {
                if (($entry['.tag'] ?? '') !== 'file') {
                    continue;
                }
                $files[] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'],
                    'size' => $entry['size'] ?? 0,
                    'is_dir' => false,
                    'modified_at' => $entry['server_modified'] ?? null,
                ];
            }
        };

        $append($response['entries'] ?? []);

        while (! empty($response['has_more'])) {
            $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder/continue', [
                'json' => ['cursor' => $response['cursor']],
            ]);
            $append($response['entries'] ?? []);
        }

        return $files;
    }

    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string
    {
        $response = $this->apiRequest('https://api.dropboxapi.com/2/files/get_temporary_link', [
            'json' => ['path' => $this->fullPath($remotePath)],
        ]);

        return $response['link'] ?? null;
    }

    public function listFolders(string $absolutePath = ''): array
    {
        $entries = [];
        $params = ['path' => $absolutePath];

        $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder', [
            'json' => $params,
        ]);

        foreach ($response['entries'] ?? [] as $entry) {
            if (($entry['.tag'] ?? '') === 'folder') {
                $entries[] = [
                    'name' => $entry['name'],
                    'path' => $entry['path_display'],
                ];
            }
        }

        while (! empty($response['has_more'])) {
            $response = $this->apiRequest('https://api.dropboxapi.com/2/files/list_folder/continue', [
                'json' => ['cursor' => $response['cursor']],
            ]);

            foreach ($response['entries'] ?? [] as $entry) {
                if (($entry['.tag'] ?? '') === 'folder') {
                    $entries[] = [
                        'name' => $entry['name'],
                        'path' => $entry['path_display'],
                    ];
                }
            }
        }

        usort($entries, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $entries;
    }

    public function test(): bool
    {
        $response = $this->apiRequest('https://api.dropboxapi.com/2/users/get_current_account', [
            'json' => null,
        ]);

        return isset($response['account_id']);
    }

    protected function getAccessToken(): string
    {
        try {
            return decrypt($this->config['access_token'] ?? '');
        } catch (DecryptException) {
            throw new RuntimeException('Dropbox credentials could not be decrypted. The APP_KEY may have changed. Please reconnect Dropbox.');
        }
    }

    protected function refreshAccessToken(): string
    {
        try {
            $appKey = decrypt($this->config['app_key'] ?? '');
            $appSecret = decrypt($this->config['app_secret'] ?? '');
            $refreshToken = decrypt($this->config['refresh_token'] ?? '');
        } catch (DecryptException) {
            throw new RuntimeException('Dropbox credentials could not be decrypted. The APP_KEY may have changed. Please reconnect Dropbox.');
        }

        $response = Http::asForm()->post('https://api.dropbox.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $appKey,
            'client_secret' => $appSecret,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to refresh Dropbox access token: '.$response->body());
        }

        $data = $response->json();
        $newAccessToken = $data['access_token'];

        // Persist the new access token
        $config = $this->destination->config;
        $config['access_token'] = encrypt($newAccessToken);
        $this->destination->update(['config' => $config]);
        $this->config = $config;

        return $newAccessToken;
    }

    protected function apiRequest(string $url, array $options): array
    {
        $accessToken = $this->getAccessToken();
        $attempt = 0;

        while (true) {
            $response = $this->makeRequest($url, $options, $accessToken);

            // If token expired, refresh and retry once (does not count against
            // the transient-retry budget).
            if ($response->status() === 401) {
                $accessToken = $this->refreshAccessToken();
                $response = $this->makeRequest($url, $options, $accessToken);
            }

            // Dropbox throttles with 429 (often with a Retry-After) and returns
            // 5xx on transient server errors. Retry the individual request with
            // backoff so a single blip on one chunk can't abort an entire
            // multipart upload (P1-64). Each request (start/append/finish) runs
            // through here, so every chunk gets its own retries.
            if ($this->isTransient($response) && $attempt < self::MAX_TRANSIENT_RETRIES) {
                $attempt++;
                $this->backoff($response, $attempt);

                continue;
            }

            if ($response->failed()) {
                throw new RuntimeException("Dropbox API error [{$response->status()}]: ".$response->body());
            }

            $body = $response->body();
            if (empty($body)) {
                return [];
            }

            return $response->json() ?? [];
        }
    }

    protected function isTransient(Response $response): bool
    {
        return $response->status() === 429 || $response->status() >= 500;
    }

    /**
     * Sleep before retrying a throttled/failed request: honour Dropbox's
     * Retry-After header when present, otherwise exponential backoff (capped).
     */
    protected function backoff(Response $response, int $attempt): void
    {
        $retryAfter = (int) $response->header('Retry-After');
        $seconds = $retryAfter > 0 ? $retryAfter : min(2 ** $attempt, 60);

        Sleep::for($seconds)->seconds();
    }

    protected function makeRequest(string $url, array $options, string $accessToken): \Illuminate\Http\Client\Response
    {
        $request = Http::withToken($accessToken)->timeout(600);

        // For Dropbox Business: select the authorizing team member's account
        $teamMemberId = $this->config['team_member_id'] ?? null;
        if ($teamMemberId) {
            $request = $request->withHeaders([
                'Dropbox-API-Select-User' => $teamMemberId,
            ]);
        }

        // For Dropbox Business: set path root to team namespace so team folders are accessible
        $rootNamespaceId = $this->config['root_namespace_id'] ?? null;
        if ($rootNamespaceId) {
            $request = $request->withHeaders([
                'Dropbox-API-Path-Root' => json_encode([
                    '.tag' => 'root',
                    'root' => $rootNamespaceId,
                ]),
            ]);
        }

        if (isset($options['headers'])) {
            $request = $request->withHeaders($options['headers']);
        }

        if (isset($options['body'])) {
            return $request->withBody($options['body'], $options['headers']['Content-Type'] ?? 'application/octet-stream')
                ->post($url);
        }

        if (array_key_exists('json', $options)) {
            if ($options['json'] === null) {
                return $request->withBody('null', 'application/json')->post($url);
            }

            return $request->post($url, $options['json']);
        }

        return $request->post($url);
    }

    protected function fullPath(string $relativePath): string
    {
        return $this->basePath.'/'.ltrim($relativePath, '/');
    }
}

# SimpleAd Manager — Feature Spec: Backup Management

---

## Overview

Full backup management system for WordPress sites. Supports database-only and full backups (files + database), multiple storage destinations (Local, Dropbox, S3-compatible), scheduling, retention policies, and one-click restore. Leverages the WordPress connector plugin endpoints already built.

**Primary test storage: Dropbox.**

---

## PART 1: DATABASE SCHEMA

### Migration: `backup_configs`

Per-site backup configuration:

```php
Schema::create('backup_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    // Scheduling
    $table->boolean('is_enabled')->default(false);
    $table->string('frequency')->default('daily'); // hourly, daily, weekly, monthly
    $table->string('time')->default('03:00'); // HH:MM — when to run
    $table->integer('day_of_week')->nullable(); // 0=Sunday, 6=Saturday (for weekly)
    $table->integer('day_of_month')->nullable(); // 1-28 (for monthly)
    $table->string('timezone')->default('Europe/Bucharest');
    
    // What to backup
    $table->string('type')->default('full'); // full, database
    
    // Exclusions
    $table->json('exclude_paths')->nullable(); // ["/wp-content/cache", "/wp-content/upgrade"]
    $table->json('exclude_tables')->nullable(); // ["wp_statistics_*", "wp_actionscheduler_*"]
    
    // Storage
    $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
    
    // Retention
    $table->string('retention_type')->default('count'); // count, days
    $table->integer('retention_value')->default(10); // keep last 10 backups OR last 30 days
    
    // Pre-update backups
    $table->boolean('backup_before_updates')->default(true);
    
    // State
    $table->timestamp('last_backup_at')->nullable();
    $table->timestamp('next_backup_at')->nullable();
    $table->string('last_backup_status')->nullable(); // success, failed
    
    $table->timestamps();
    
    $table->index(['site_id']);
    $table->index(['is_enabled', 'next_backup_at']);
});
```

### Migration: `storage_destinations`

```php
Schema::create('storage_destinations', function (Blueprint $table) {
    $table->id();
    
    $table->string('name'); // "My Dropbox", "Production S3", "Local Server"
    $table->string('type'); // local, dropbox, s3
    $table->json('config'); // type-specific config (encrypted sensitive fields)
    $table->boolean('is_default')->default(false);
    $table->boolean('is_active')->default(true);
    
    // Capacity tracking
    $table->bigInteger('used_bytes')->default(0);
    $table->bigInteger('quota_bytes')->nullable(); // null = unlimited
    
    // Validation
    $table->timestamp('last_tested_at')->nullable();
    $table->boolean('last_test_passed')->nullable();
    $table->text('last_test_error')->nullable();
    
    $table->timestamps();
});
```

Storage config examples:

```php
// Local
[
    'path' => '/var/backups/simplead',
]

// Dropbox
[
    'access_token' => 'encrypted_token',
    'refresh_token' => 'encrypted_refresh_token',
    'app_key' => 'encrypted_app_key',
    'app_secret' => 'encrypted_app_secret',
    'base_path' => '/SimpleAd Backups',
]

// S3-compatible (AWS S3, DigitalOcean Spaces, Backblaze B2, Wasabi, etc.)
[
    'key' => 'encrypted_access_key',
    'secret' => 'encrypted_secret_key',
    'bucket' => 'my-backups',
    'region' => 'eu-central-1',
    'endpoint' => null, // custom endpoint for non-AWS S3
    'base_path' => 'simplead-backups',
]
```

### Migration: `backups`

```php
Schema::create('backups', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
    
    // Backup info
    $table->string('type'); // full, database
    $table->string('trigger'); // scheduled, manual, pre_update
    $table->string('status')->default('pending'); // pending, in_progress, completed, failed
    $table->text('error_message')->nullable();
    
    // Files info
    $table->string('file_path')->nullable(); // path in storage destination
    $table->string('file_name')->nullable();
    $table->bigInteger('file_size')->nullable(); // bytes
    $table->string('checksum')->nullable(); // sha256 hash
    
    // What's included
    $table->boolean('includes_files')->default(false);
    $table->boolean('includes_database')->default(true);
    
    // WordPress state at time of backup
    $table->string('wp_version')->nullable();
    $table->string('php_version')->nullable();
    $table->integer('plugins_count')->nullable();
    $table->integer('themes_count')->nullable();
    $table->decimal('db_size_mb', 10, 2)->nullable();
    
    // Timing
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->integer('duration_seconds')->nullable();
    
    // Retention
    $table->boolean('is_locked')->default(false); // locked backups are never auto-deleted
    $table->string('lock_reason')->nullable(); // "pre-update", "manual lock"
    $table->timestamp('expires_at')->nullable();
    
    // Restore tracking
    $table->timestamp('last_restored_at')->nullable();
    
    $table->string('notes')->nullable();
    
    $table->timestamps();
    
    $table->index(['site_id', 'status']);
    $table->index(['site_id', 'created_at']);
    $table->index(['storage_destination_id']);
    $table->index(['expires_at']);
});
```

---

## PART 2: STORAGE DRIVERS

### 2.1 Storage Interface

```php
// app/Services/Backup/Storage/StorageDriver.php

interface StorageDriver
{
    /**
     * Upload a backup file to the storage destination
     */
    public function upload(string $localPath, string $remotePath): bool;

    /**
     * Download a backup file from storage to a local path
     */
    public function download(string $remotePath, string $localPath): bool;

    /**
     * Delete a file from storage
     */
    public function delete(string $remotePath): bool;

    /**
     * Check if a file exists
     */
    public function exists(string $remotePath): bool;

    /**
     * Get file size in bytes
     */
    public function size(string $remotePath): int;

    /**
     * List files in a directory
     */
    public function list(string $directory): array;

    /**
     * Test the connection
     */
    public function test(): array; // ['success' => bool, 'error' => ?string, 'info' => ?string]
}
```

### 2.2 Local Storage Driver

```php
// app/Services/Backup/Storage/LocalDriver.php

class LocalDriver implements StorageDriver
{
    private string $basePath;

    public function __construct(array $config)
    {
        $this->basePath = rtrim($config['path'], '/');
        
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $destination = $this->basePath . '/' . $remotePath;
        $dir = dirname($destination);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return copy($localPath, $destination);
    }

    public function download(string $remotePath, string $localPath): bool
    {
        return copy($this->basePath . '/' . $remotePath, $localPath);
    }

    public function delete(string $remotePath): bool
    {
        $path = $this->basePath . '/' . $remotePath;
        return file_exists($path) && unlink($path);
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->basePath . '/' . $remotePath);
    }

    public function size(string $remotePath): int
    {
        return filesize($this->basePath . '/' . $remotePath) ?: 0;
    }

    public function list(string $directory): array
    {
        $path = $this->basePath . '/' . $directory;
        if (!is_dir($path)) return [];
        
        return array_values(array_diff(scandir($path), ['.', '..']));
    }

    public function test(): array
    {
        $testFile = $this->basePath . '/.sam_test_' . uniqid();
        try {
            file_put_contents($testFile, 'test');
            unlink($testFile);
            
            $freeSpace = disk_free_space($this->basePath);
            return [
                'success' => true,
                'error' => null,
                'info' => 'Free space: ' . number_format($freeSpace / 1024 / 1024 / 1024, 2) . ' GB',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
```

### 2.3 Dropbox Storage Driver

```php
// app/Services/Backup/Storage/DropboxDriver.php

class DropboxDriver implements StorageDriver
{
    private string $accessToken;
    private string $refreshToken;
    private string $appKey;
    private string $appSecret;
    private string $basePath;

    public function __construct(array $config)
    {
        $this->accessToken = decrypt($config['access_token']);
        $this->refreshToken = decrypt($config['refresh_token']);
        $this->appKey = decrypt($config['app_key']);
        $this->appSecret = decrypt($config['app_secret']);
        $this->basePath = rtrim($config['base_path'] ?? '/SimpleAd Backups', '/');
    }

    /**
     * Refresh access token if expired using refresh token
     */
    private function refreshAccessToken(): void
    {
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
        ]);

        if ($response->successful()) {
            $this->accessToken = $response->json('access_token');
            
            // Update stored token
            // Note: in real implementation, update the storage_destination record
        }
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->timeout(300); // 5 min timeout for large uploads
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $fullPath = $this->basePath . '/' . $remotePath;
        $fileSize = filesize($localPath);

        try {
            // For files under 150MB, use simple upload
            if ($fileSize < 150 * 1024 * 1024) {
                return $this->simpleUpload($localPath, $fullPath);
            }
            
            // For larger files, use upload session (chunked)
            return $this->chunkedUpload($localPath, $fullPath);
        } catch (\Exception $e) {
            // If token expired, refresh and retry
            if (str_contains($e->getMessage(), 'expired_access_token')) {
                $this->refreshAccessToken();
                return $fileSize < 150 * 1024 * 1024
                    ? $this->simpleUpload($localPath, $fullPath)
                    : $this->chunkedUpload($localPath, $fullPath);
            }
            throw $e;
        }
    }

    private function simpleUpload(string $localPath, string $dropboxPath): bool
    {
        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode([
                    'path' => $dropboxPath,
                    'mode' => 'overwrite',
                    'autorename' => false,
                ]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody(file_get_contents($localPath), 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload');

        return $response->successful();
    }

    private function chunkedUpload(string $localPath, string $dropboxPath): bool
    {
        $chunkSize = 8 * 1024 * 1024; // 8MB chunks
        $fileHandle = fopen($localPath, 'rb');
        $fileSize = filesize($localPath);
        $offset = 0;

        // Start upload session
        $chunk = fread($fileHandle, $chunkSize);
        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode(['close' => false]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody($chunk, 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload_session/start');

        $sessionId = $response->json('session_id');
        $offset += strlen($chunk);

        // Append chunks
        while (!feof($fileHandle)) {
            $chunk = fread($fileHandle, $chunkSize);
            if (empty($chunk)) break;

            $isLast = feof($fileHandle) || ($offset + strlen($chunk) >= $fileSize);

            if (!$isLast) {
                Http::withToken($this->accessToken)
                    ->withHeaders([
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                            'close' => false,
                        ]),
                        'Content-Type' => 'application/octet-stream',
                    ])
                    ->withBody($chunk, 'application/octet-stream')
                    ->post('https://content.dropboxapi.com/2/files/upload_session/append_v2');
            } else {
                // Finish upload
                Http::withToken($this->accessToken)
                    ->withHeaders([
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                            'commit' => [
                                'path' => $dropboxPath,
                                'mode' => 'overwrite',
                                'autorename' => false,
                            ],
                        ]),
                        'Content-Type' => 'application/octet-stream',
                    ])
                    ->withBody($chunk, 'application/octet-stream')
                    ->post('https://content.dropboxapi.com/2/files/upload_session/finish');
            }

            $offset += strlen($chunk);
        }

        fclose($fileHandle);
        return true;
    }

    public function download(string $remotePath, string $localPath): bool
    {
        $fullPath = $this->basePath . '/' . $remotePath;
        
        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode(['path' => $fullPath]),
            ])
            ->post('https://content.dropboxapi.com/2/files/download');

        if ($response->successful()) {
            file_put_contents($localPath, $response->body());
            return true;
        }
        return false;
    }

    public function delete(string $remotePath): bool
    {
        $response = $this->api()->post('https://api.dropboxapi.com/2/files/delete_v2', [
            'path' => $this->basePath . '/' . $remotePath,
        ]);
        return $response->successful();
    }

    public function exists(string $remotePath): bool
    {
        try {
            $response = $this->api()->post('https://api.dropboxapi.com/2/files/get_metadata', [
                'path' => $this->basePath . '/' . $remotePath,
            ]);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function size(string $remotePath): int
    {
        $response = $this->api()->post('https://api.dropboxapi.com/2/files/get_metadata', [
            'path' => $this->basePath . '/' . $remotePath,
        ]);
        return $response->json('size') ?? 0;
    }

    public function list(string $directory): array
    {
        $response = $this->api()->post('https://api.dropboxapi.com/2/files/list_folder', [
            'path' => $this->basePath . '/' . $directory,
            'recursive' => false,
        ]);

        if (!$response->successful()) return [];

        return collect($response->json('entries'))
            ->map(fn ($entry) => [
                'name' => $entry['name'],
                'path' => $entry['path_display'],
                'size' => $entry['size'] ?? 0,
                'type' => $entry['.tag'], // file or folder
                'modified' => $entry['server_modified'] ?? null,
            ])
            ->toArray();
    }

    public function test(): array
    {
        try {
            $response = $this->api()->post('https://api.dropboxapi.com/2/users/get_current_account');

            if ($response->successful()) {
                $account = $response->json();
                
                // Get space usage
                $spaceResponse = $this->api()->post('https://api.dropboxapi.com/2/users/get_space_usage');
                $used = $spaceResponse->json('used') ?? 0;
                $allocated = $spaceResponse->json('allocation.allocated') ?? 0;

                return [
                    'success' => true,
                    'error' => null,
                    'info' => sprintf(
                        'Connected as %s. Used: %s / %s',
                        $account['name']['display_name'] ?? 'Unknown',
                        number_format($used / 1024 / 1024 / 1024, 2) . ' GB',
                        $allocated > 0 ? number_format($allocated / 1024 / 1024 / 1024, 2) . ' GB' : 'Unlimited'
                    ),
                ];
            }

            return ['success' => false, 'error' => 'Could not connect to Dropbox', 'info' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
```

### 2.4 S3 Storage Driver

```php
// app/Services/Backup/Storage/S3Driver.php

use Aws\S3\S3Client;

class S3Driver implements StorageDriver
{
    private S3Client $client;
    private string $bucket;
    private string $basePath;

    public function __construct(array $config)
    {
        $options = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => decrypt($config['key']),
                'secret' => decrypt($config['secret']),
            ],
        ];

        // Custom endpoint for non-AWS S3 (DigitalOcean Spaces, Backblaze B2, etc.)
        if (!empty($config['endpoint'])) {
            $options['endpoint'] = $config['endpoint'];
            $options['use_path_style_endpoint'] = true;
        }

        $this->client = new S3Client($options);
        $this->bucket = $config['bucket'];
        $this->basePath = trim($config['base_path'] ?? '', '/');
    }

    private function fullPath(string $path): string
    {
        return $this->basePath ? $this->basePath . '/' . $path : $path;
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $result = $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'SourceFile' => $localPath,
        ]);
        return $result['@metadata']['statusCode'] === 200;
    }

    public function download(string $remotePath, string $localPath): bool
    {
        $result = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
            'SaveAs' => $localPath,
        ]);
        return $result['@metadata']['statusCode'] === 200;
    }

    public function delete(string $remotePath): bool
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);
        return true;
    }

    public function exists(string $remotePath): bool
    {
        return $this->client->doesObjectExist($this->bucket, $this->fullPath($remotePath));
    }

    public function size(string $remotePath): int
    {
        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $this->fullPath($remotePath),
        ]);
        return (int) $result['ContentLength'];
    }

    public function list(string $directory): array
    {
        $result = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $this->fullPath($directory) . '/',
            'Delimiter' => '/',
        ]);

        return collect($result['Contents'] ?? [])
            ->map(fn ($obj) => [
                'name' => basename($obj['Key']),
                'path' => $obj['Key'],
                'size' => $obj['Size'],
                'modified' => $obj['LastModified']->format('c'),
            ])
            ->toArray();
    }

    public function test(): array
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return [
                'success' => true,
                'error' => null,
                'info' => "Connected to bucket: {$this->bucket}",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
```

### 2.5 Storage Factory

```php
// app/Services/Backup/Storage/StorageFactory.php

class StorageFactory
{
    public static function make(StorageDestination $destination): StorageDriver
    {
        return match ($destination->type) {
            'local' => new LocalDriver($destination->config),
            'dropbox' => new DropboxDriver($destination->config),
            's3' => new S3Driver($destination->config),
            default => throw new \InvalidArgumentException("Unknown storage type: {$destination->type}"),
        };
    }
}
```

---

## PART 3: DROPBOX OAUTH FLOW

Since Dropbox requires OAuth2 for authentication, we need a setup flow in the settings.

### 3.1 Dropbox App Setup

Create a Dropbox App at https://www.dropbox.com/developers/apps with:
- App type: Scoped access
- Access type: Full Dropbox (or App folder)
- Permissions: files.content.write, files.content.read, account_info.read
- Redirect URI: `https://manager.simplead.ro/settings/storage/dropbox/callback`

### 3.2 OAuth Routes

```php
// routes/web.php — add inside settings group

Route::get('/settings/storage/dropbox/auth', [DropboxAuthController::class, 'redirect'])->name('dropbox.auth');
Route::get('/settings/storage/dropbox/callback', [DropboxAuthController::class, 'callback'])->name('dropbox.callback');
```

### 3.3 OAuth Controller

```php
// app/Http/Controllers/DropboxAuthController.php

class DropboxAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $appKey = config('services.dropbox.app_key');

        $params = http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => route('dropbox.callback'),
            'token_access_type' => 'offline', // get refresh token
            'state' => csrf_token(),
        ]);

        return redirect("https://www.dropbox.com/oauth2/authorize?{$params}");
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');

        // Exchange code for tokens
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => config('services.dropbox.app_key'),
            'client_secret' => config('services.dropbox.app_secret'),
            'redirect_uri' => route('dropbox.callback'),
        ]);

        if ($response->failed()) {
            return redirect()->route('settings.general')
                ->with('error', 'Failed to connect to Dropbox');
        }

        $data = $response->json();

        // Create or update storage destination
        StorageDestination::updateOrCreate(
            ['type' => 'dropbox'],
            [
                'name' => 'Dropbox',
                'config' => [
                    'access_token' => encrypt($data['access_token']),
                    'refresh_token' => encrypt($data['refresh_token']),
                    'app_key' => encrypt(config('services.dropbox.app_key')),
                    'app_secret' => encrypt(config('services.dropbox.app_secret')),
                    'base_path' => '/SimpleAd Backups',
                ],
                'is_active' => true,
            ]
        );

        return redirect()->route('settings.general')
            ->with('success', 'Dropbox connected successfully!');
    }
}
```

### 3.4 Config

```php
// config/services.php — add:
'dropbox' => [
    'app_key' => env('DROPBOX_APP_KEY'),
    'app_secret' => env('DROPBOX_APP_SECRET'),
],
```

```env
# .env
DROPBOX_APP_KEY=your_app_key
DROPBOX_APP_SECRET=your_app_secret
```

---

## PART 4: BACKUP JOBS

### 4.1 Create Backup Job

```php
// app/Jobs/CreateBackup.php

class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes max

    public function __construct(
        public Site $site,
        public string $type = 'full', // full, database
        public string $trigger = 'manual', // manual, scheduled, pre_update
        public ?int $storageDestinationId = null
    ) {}

    public function handle(): void
    {
        $api = new WordPressApiService($this->site);

        // Determine storage destination
        $destinationId = $this->storageDestinationId
            ?? $this->site->backupConfig?->storage_destination_id
            ?? StorageDestination::where('is_default', true)->first()?->id;

        $destination = $destinationId ? StorageDestination::find($destinationId) : null;

        // Create backup record
        $backup = Backup::create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destinationId,
            'type' => $this->type,
            'trigger' => $this->trigger,
            'status' => 'in_progress',
            'includes_files' => $this->type === 'full',
            'includes_database' => true,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'plugins_count' => $this->site->plugins()->count(),
            'themes_count' => $this->site->themes()->count(),
            'db_size_mb' => $this->site->db_size_mb,
            'started_at' => now(),
        ]);

        try {
            // Step 1: Request backup from WordPress
            $tempDir = storage_path("app/backups/temp/{$backup->id}");
            mkdir($tempDir, 0755, true);

            if ($this->type === 'database' || $this->type === 'full') {
                $dbData = $api->request('GET', 'backup/db');
                // The endpoint streams the SQL dump — save response to file
                $dbPath = "{$tempDir}/database.sql.gz";
                $this->downloadFromWp($api, 'backup/db', $dbPath);
            }

            if ($this->type === 'full') {
                $filesPath = "{$tempDir}/files.zip";
                $this->downloadFromWp($api, 'backup/files', $filesPath);
            }

            // Step 2: Create combined archive
            $siteDomain = Str::slug($this->site->domain);
            $date = now()->format('Y-m-d_His');
            $fileName = "{$siteDomain}_{$date}_{$this->type}.zip";
            $archivePath = "{$tempDir}/{$fileName}";

            $zip = new \ZipArchive();
            $zip->open($archivePath, \ZipArchive::CREATE);

            if (file_exists("{$tempDir}/database.sql.gz")) {
                $zip->addFile("{$tempDir}/database.sql.gz", 'database.sql.gz');
            }
            if (file_exists("{$tempDir}/files.zip")) {
                $zip->addFile("{$tempDir}/files.zip", 'files.zip');
            }

            // Add metadata
            $zip->addFromString('backup-meta.json', json_encode([
                'site' => $this->site->name,
                'domain' => $this->site->domain,
                'type' => $this->type,
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'created_at' => now()->toIso8601String(),
                'connector_version' => SAM_CONNECTOR_VERSION ?? '1.0.0',
            ], JSON_PRETTY_PRINT));

            $zip->close();

            $fileSize = filesize($archivePath);
            $checksum = hash_file('sha256', $archivePath);

            // Step 3: Upload to storage destination
            $remotePath = "{$siteDomain}/" . now()->format('Y/m') . "/{$fileName}";

            if ($destination) {
                $driver = StorageFactory::make($destination);
                $driver->upload($archivePath, $remotePath);
                
                // Update used space
                $destination->increment('used_bytes', $fileSize);
            }

            // Step 4: Update backup record
            $backup->update([
                'status' => 'completed',
                'file_path' => $remotePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'completed_at' => now(),
                'duration_seconds' => now()->diffInSeconds($backup->started_at),
            ]);

            // Update site
            $this->site->update([
                'last_backup_at' => now(),
                'backup_ok' => true,
            ]);

            // Update backup config
            if ($this->site->backupConfig) {
                $this->site->backupConfig->update([
                    'last_backup_at' => now(),
                    'last_backup_status' => 'success',
                ]);
            }

            // Step 5: Clean up temp files
            $this->cleanupTemp($tempDir);

            // Step 6: Apply retention policy
            $this->applyRetention();

        } catch (\Exception $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => now()->diffInSeconds($backup->started_at),
            ]);

            $this->site->update(['backup_ok' => false]);

            if ($this->site->backupConfig) {
                $this->site->backupConfig->update(['last_backup_status' => 'failed']);
            }

            // Send failure notification
            NotifyBackupFailed::dispatch($this->site, $backup, $e->getMessage());

            // Clean up
            if (isset($tempDir)) {
                $this->cleanupTemp($tempDir);
            }

            throw $e;
        }
    }

    private function downloadFromWp(WordPressApiService $api, string $endpoint, string $saveTo): void
    {
        // Make authenticated streaming request to WP connector
        $url = rtrim($this->site->api_endpoint, '/') . '/' . $endpoint;
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', 'GET/simplead/v1/' . $endpoint . $timestamp, decrypt($this->site->api_secret));

        $response = Http::withHeaders([
            'X-SAM-Key' => decrypt($this->site->api_key),
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Signature' => $signature,
        ])->timeout(600)->sink($saveTo)->get($url);

        if ($response->failed()) {
            throw new \Exception("Failed to download backup from WordPress: {$endpoint}");
        }
    }

    private function cleanupTemp(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    private function applyRetention(): void
    {
        $config = $this->site->backupConfig;
        if (!$config) return;

        $query = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->where('is_locked', false);

        if ($config->retention_type === 'count') {
            $backupsToDelete = (clone $query)
                ->orderByDesc('created_at')
                ->skip($config->retention_value)
                ->get();
        } else { // days
            $backupsToDelete = (clone $query)
                ->where('created_at', '<', now()->subDays($config->retention_value))
                ->get();
        }

        foreach ($backupsToDelete as $oldBackup) {
            // Delete from storage
            if ($oldBackup->storage_destination_id && $oldBackup->file_path) {
                try {
                    $destination = StorageDestination::find($oldBackup->storage_destination_id);
                    if ($destination) {
                        $driver = StorageFactory::make($destination);
                        $driver->delete($oldBackup->file_path);
                        $destination->decrement('used_bytes', $oldBackup->file_size ?? 0);
                    }
                } catch (\Exception $e) {
                    \Log::warning("Could not delete old backup file: " . $e->getMessage());
                }
            }
            $oldBackup->delete();
        }
    }
}
```

### 4.2 Restore Backup Job

```php
// app/Jobs/RestoreBackup.php

class RestoreBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max

    public function __construct(
        public Backup $backup
    ) {}

    public function handle(): void
    {
        $site = $this->backup->site;
        $api = new WordPressApiService($site);
        $destination = StorageDestination::find($this->backup->storage_destination_id);

        $tempDir = storage_path("app/backups/restore/{$this->backup->id}");
        mkdir($tempDir, 0755, true);

        try {
            // Step 1: Download backup from storage
            $localArchive = "{$tempDir}/{$this->backup->file_name}";
            
            if ($destination) {
                $driver = StorageFactory::make($destination);
                $driver->download($this->backup->file_path, $localArchive);
            }

            // Step 2: Verify checksum
            if ($this->backup->checksum) {
                $actualChecksum = hash_file('sha256', $localArchive);
                if ($actualChecksum !== $this->backup->checksum) {
                    throw new \Exception('Backup file checksum mismatch — file may be corrupted');
                }
            }

            // Step 3: Extract archive
            $zip = new \ZipArchive();
            $zip->open($localArchive);
            $zip->extractTo($tempDir);
            $zip->close();

            // Step 4: Upload to WordPress and trigger restore
            if (file_exists("{$tempDir}/database.sql.gz")) {
                $api->request('POST', 'backup/restore', [
                    'type' => 'database',
                    // Send the file content base64 encoded
                    'data' => base64_encode(file_get_contents("{$tempDir}/database.sql.gz")),
                ]);
            }

            if (file_exists("{$tempDir}/files.zip")) {
                $api->request('POST', 'backup/restore', [
                    'type' => 'files',
                    'data' => base64_encode(file_get_contents("{$tempDir}/files.zip")),
                ]);
            }

            // Step 5: Update records
            $this->backup->update(['last_restored_at' => now()]);

            // Step 6: Trigger a sync to refresh site data
            SyncWordPressSite::dispatch($site);

        } catch (\Exception $e) {
            \Log::error("Restore failed for backup {$this->backup->id}: " . $e->getMessage());
            throw $e;
        } finally {
            // Clean up temp
            if (is_dir($tempDir)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                }
                rmdir($tempDir);
            }
        }
    }
}
```

### 4.3 Scheduler

```php
// Add to existing scheduler

// Run scheduled backups every hour (checks which ones are due)
Schedule::call(function () {
    BackupConfig::where('is_enabled', true)
        ->where(function ($q) {
            $q->whereNull('next_backup_at')
              ->orWhere('next_backup_at', '<=', now());
        })
        ->with('site')
        ->each(function ($config) {
            CreateBackup::dispatch(
                $config->site,
                $config->type,
                'scheduled',
                $config->storage_destination_id
            );

            // Calculate next backup time
            $next = match($config->frequency) {
                'hourly' => now()->addHour(),
                'daily' => now()->addDay()->setTimeFromTimeString($config->time),
                'weekly' => now()->addWeek()->startOfWeek()->addDays($config->day_of_week ?? 0)->setTimeFromTimeString($config->time),
                'monthly' => now()->addMonth()->startOfMonth()->addDays(($config->day_of_month ?? 1) - 1)->setTimeFromTimeString($config->time),
            };

            $config->update(['next_backup_at' => $next]);
        });
})->everyFifteenMinutes();

// Clean expired backups daily
Schedule::call(function () {
    Backup::where('expires_at', '<=', now())
        ->where('is_locked', false)
        ->each(function ($backup) {
            // Delete from storage
            // ... same as retention logic
            $backup->delete();
        });
})->daily();
```

---

## PART 5: WORDPRESS CONNECTOR — BACKUP ENDPOINTS

Add these to the existing WordPress connector plugin:

```php
// includes/class-backup.php (add to plugin)

class SAM_Backup {

    public static function create_db_backup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $tempFile = tempnam(sys_get_temp_dir(), 'sam_db_');

        $handle = gzopen($tempFile, 'wb9');

        foreach ($tables as $table) {
            $tableName = $table[0];

            // Table structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$tableName}`", ARRAY_N);
            gzwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");
            gzwrite($handle, $create[1] . ";\n\n");

            // Table data — chunked for memory efficiency
            $offset = 0;
            $limit = 1000;
            
            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$tableName}` LIMIT {$offset}, {$limit}", ARRAY_A);
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    $values = array_map(function ($val) use ($wpdb) {
                        return $val === null ? 'NULL' : "'" . $wpdb->_real_escape($val) . "'";
                    }, $row);

                    gzwrite($handle, "INSERT INTO `{$tableName}` VALUES (" . implode(',', $values) . ");\n");
                }

                $offset += $limit;
            }

            gzwrite($handle, "\n\n");
        }

        gzclose($handle);

        // Stream the file
        $size = filesize($tempFile);
        header('Content-Type: application/gzip');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="database.sql.gz"');
        readfile($tempFile);
        unlink($tempFile);
        exit;
    }

    public static function create_files_backup(WP_REST_Request $request): WP_REST_Response {
        $tempFile = tempnam(sys_get_temp_dir(), 'sam_files_');
        
        $zip = new ZipArchive();
        $zip->open($tempFile, ZipArchive::CREATE);

        // Default exclusions
        $exclude = [
            'wp-content/cache',
            'wp-content/backup',
            'wp-content/backups',
            'wp-content/upgrade',
            'wp-content/tmp',
            '.git',
            'node_modules',
        ];

        // Add wp-content directory
        $wpContentDir = WP_CONTENT_DIR;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wpContentDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace(ABSPATH, '', $file->getRealPath());

            // Check exclusions
            $skip = false;
            foreach ($exclude as $excl) {
                if (str_starts_with($relativePath, $excl)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Skip files > 100MB
            if ($file->getSize() > 100 * 1024 * 1024) continue;

            $zip->addFile($file->getRealPath(), $relativePath);
        }

        $zip->close();

        // Stream
        $size = filesize($tempFile);
        header('Content-Type: application/zip');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="files.zip"');
        readfile($tempFile);
        unlink($tempFile);
        exit;
    }

    public static function restore_backup(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type'); // database, files
        $data = base64_decode($request->get_param('data'));

        if ($type === 'database') {
            return self::restore_database($data);
        } elseif ($type === 'files') {
            return self::restore_files($data);
        }

        return new WP_REST_Response(['error' => 'Invalid restore type'], 400);
    }

    private static function restore_database(string $gzData): WP_REST_Response {
        global $wpdb;

        $tempFile = tempnam(sys_get_temp_dir(), 'sam_restore_');
        file_put_contents($tempFile, $gzData);

        $handle = gzopen($tempFile, 'rb');
        $sql = '';

        while (!gzeof($handle)) {
            $line = gzgets($handle);
            $sql .= $line;

            if (str_ends_with(trim($line), ';')) {
                $wpdb->query($sql);
                $sql = '';
            }
        }

        gzclose($handle);
        unlink($tempFile);

        return new WP_REST_Response(['success' => true, 'message' => 'Database restored'], 200);
    }

    private static function restore_files(string $zipData): WP_REST_Response {
        $tempFile = tempnam(sys_get_temp_dir(), 'sam_restore_');
        file_put_contents($tempFile, $zipData);

        $zip = new ZipArchive();
        $zip->open($tempFile);
        $zip->extractTo(ABSPATH);
        $zip->close();

        unlink($tempFile);

        return new WP_REST_Response(['success' => true, 'message' => 'Files restored'], 200);
    }
}
```

Register backup routes in the plugin's `class-api.php`:

```php
register_rest_route($namespace, '/backup/db', [
    'methods' => 'GET',
    'callback' => ['SAM_Backup', 'create_db_backup'],
    'permission_callback' => $auth,
]);
register_rest_route($namespace, '/backup/files', [
    'methods' => 'GET',
    'callback' => ['SAM_Backup', 'create_files_backup'],
    'permission_callback' => $auth,
]);
register_rest_route($namespace, '/backup/restore', [
    'methods' => 'POST',
    'callback' => ['SAM_Backup', 'restore_backup'],
    'permission_callback' => $auth,
]);
```

---

## PART 6: UI PAGES

### 6.1 Site Backups Page (`/sites/{site}/backups`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Backups — simplead.ro                                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Quick Actions ─────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  [🗄 Backup Database]  [📦 Full Backup]                         │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Schedule ──────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Status: ● Active — Daily at 03:00 (Europe/Bucharest)          │ │
│  │  Storage: Dropbox — /SimpleAd Backups                           │ │
│  │  Retention: Keep last 10 backups                                 │ │
│  │  Type: Full (Files + Database)                                   │ │
│  │  Next backup: Tomorrow at 03:00                     [Configure] │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Backup History ────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Date            │ Type     │ Size    │ Storage  │ Status│ Acts │ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  Feb 2, 03:00    │ Full     │ 245 MB  │ Dropbox  │ ✅   │ ↓ 🔄 🔒│
│  │  Feb 1, 03:00    │ Full     │ 243 MB  │ Dropbox  │ ✅   │ ↓ 🔄   │
│  │  Jan 31, 03:00   │ Full     │ 241 MB  │ Dropbox  │ ✅   │ ↓ 🔄   │
│  │  Jan 30, 15:22   │ Database │ 12 MB   │ Dropbox  │ ✅   │ ↓ 🔄   │
│  │  Jan 30, 03:00   │ Full     │ 240 MB  │ Dropbox  │ ❌   │       │
│  │                   │          │         │          │ Timeout      │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  Actions: ↓ = Download, 🔄 = Restore, 🔒 = Lock (prevent deletion)  │
│                                                                       │
│  ┌─ Storage Usage ─────────────────────────────────────────────────┐ │
│  │  Dropbox: 2.1 GB used for this site (8 backups)                │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.2 Backup Schedule Configuration Modal

```
┌─────────────────────────────────────────────────────────────────────┐
│  Configure Backup Schedule                                   [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  [✓] Enable scheduled backups                                        │
│                                                                       │
│  Backup Type                                                         │
│  ( ● Full (Files + Database)  ( ○ Database Only )                    │
│                                                                       │
│  Frequency                                                           │
│  [ Daily ▼ ]                                                         │
│                                                                       │
│  Time                                                                │
│  [ 03:00 ▼ ]    Timezone: [ Europe/Bucharest ▼ ]                    │
│                                                                       │
│  Storage Destination                                                 │
│  [ Dropbox — /SimpleAd Backups ▼ ]                                  │
│                                                                       │
│  Retention                                                           │
│  Keep [ last 10 ▼ ] backups                                         │
│  OR  Keep backups from last [ __ ] days                              │
│                                                                       │
│  [✓] Auto-backup before plugin/theme/core updates                   │
│                                                                       │
│                                          [Cancel]  [Save Schedule]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.3 Storage Destinations (in General Settings)

Add a storage section to the General Settings page:

```
┌─ Storage Destinations ─────────────────────────────────────────────┐
│                                                      [+ Add Storage] │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  📦 Dropbox — SimpleAd Backups                                  │ │
│  │  Connected as: Andrei  •  Default  •  Used: 5.2 GB / 2 TB      │ │
│  │  Last tested: 1 hour ago — ✅ OK                                │ │
│  │                                          [Test] [Edit] [Delete] │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  🖥 Local Server — /var/backups/simplead                         │ │
│  │  Used: 12.4 GB  •  Free: 45.6 GB                               │ │
│  │                                          [Test] [Edit] [Delete] │ │
│  └─────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────┘

Add Storage Modal:
┌─────────────────────────────────────────────────────────────────────┐
│  Add Storage Destination                                     [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Storage Type                                                        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                          │
│  │ 📦       │  │ 🖥       │  │ ☁️       │                          │
│  │ Dropbox   │  │ Local    │  │ S3       │                          │
│  └──────────┘  └──────────┘  └──────────┘                          │
│                                                                       │
│  ── For Dropbox: ──                                                  │
│  Click to connect:  [🔗 Connect Dropbox Account]                    │
│  (Opens Dropbox OAuth flow)                                         │
│                                                                       │
│  Base Path:  [ /SimpleAd Backups ]                                  │
│                                                                       │
│  ── For Local: ──                                                    │
│  Storage Path:  [ /var/backups/simplead ]                           │
│                                                                       │
│  ── For S3: ──                                                       │
│  Access Key:  [ _________________________ ]                          │
│  Secret Key:  [ _________________________ ]                          │
│  Bucket:      [ _________________________ ]                          │
│  Region:      [ eu-central-1 ▼ ]                                    │
│  Endpoint:    [ _________________________ ] (optional, for non-AWS) │
│  Base Path:   [ simplead-backups ]                                  │
│                                                                       │
│  [✓] Set as default storage                                         │
│                                                                       │
│                              [Cancel]  [Test & Save]                 │
└─────────────────────────────────────────────────────────────────────┘
```

### 6.4 Restore Confirmation Modal

```
┌─────────────────────────────────────────────────────────────────────┐
│  ⚠️ Restore Backup                                           [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  You are about to restore simplead.ro from:                         │
│                                                                       │
│  📦 Full Backup — Feb 2, 2026 at 03:00                             │
│  Size: 245 MB  •  Storage: Dropbox                                  │
│  WP 6.4.3  •  PHP 8.2  •  18 plugins                              │
│                                                                       │
│  ⚠️ This will:                                                       │
│  • Replace the current database with the backup version             │
│  • Overwrite files with the backup versions (if full backup)        │
│  • This action cannot be undone                                      │
│                                                                       │
│  [✓] I understand this will overwrite current site data             │
│                                                                       │
│  Recommendation: Create a backup of the current state first         │
│  [📦 Backup Current State First]                                    │
│                                                                       │
│                                    [Cancel]  [Restore Backup]        │
└─────────────────────────────────────────────────────────────────────┘
```

---

## PART 7: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   └── SiteBackups.php                 # Main backups page
│
├── Settings/
│   └── StorageSettings.php             # Storage destinations management (in general settings)
│
├── Components/
│   ├── BackupHistoryTable.php          # Backup history with actions
│   ├── BackupScheduleForm.php          # Schedule configuration modal
│   ├── StorageDestinationForm.php      # Add/edit storage destination modal
│   └── RestoreConfirmation.php         # Restore confirmation modal
│
└── Actions/
    ├── TriggerBackup.php               # Manual backup trigger
    └── TriggerRestore.php              # Restore trigger
```

---

## PART 8: PRE-UPDATE BACKUP INTEGRATION

When updating plugins/themes/core via the Updates page, automatically create a backup first if enabled:

```php
// In the update plugin/theme Livewire action:

public function updatePlugin(string $pluginFile): void
{
    $config = $this->site->backupConfig;

    if ($config?->backup_before_updates) {
        // Create pre-update backup synchronously or wait for it
        $backup = CreateBackup::dispatchSync(
            $this->site,
            'database', // quick DB backup before updates
            'pre_update',
            $config->storage_destination_id
        );
    }

    // Proceed with update
    $api = new WordPressApiService($this->site);
    $result = $api->updatePlugins([$pluginFile]);

    // Log the update
    UpdateLog::create([...]);

    // Refresh data
    SyncWordPressSite::dispatch($this->site);
}
```

---

## PART 9: IMPLEMENTATION CHECKLIST

### Database & Models
- [ ] Create migration: backup_configs
- [ ] Create migration: storage_destinations
- [ ] Create migration: backups
- [ ] Update sites migration (add last_backup_at, backup_ok if not present)
- [ ] Create model: BackupConfig (with casts, relationships)
- [ ] Create model: StorageDestination (with casts)
- [ ] Create model: Backup (with casts, relationships, computed attributes)
- [ ] Add relationships to Site model (backupConfig, backups)

### Storage Drivers
- [ ] Create StorageDriver interface
- [ ] Create LocalDriver
- [ ] Create DropboxDriver (with OAuth token refresh, chunked upload)
- [ ] Create S3Driver (with AWS SDK — `composer require aws/aws-sdk-php`)
- [ ] Create StorageFactory

### Dropbox OAuth
- [ ] Add Dropbox config to config/services.php and .env
- [ ] Create DropboxAuthController (redirect + callback)
- [ ] Add OAuth routes
- [ ] Test the full OAuth flow

### WordPress Connector Plugin Update
- [ ] Add SAM_Backup class to plugin (create_db_backup, create_files_backup, restore_backup)
- [ ] Register backup REST routes in plugin
- [ ] Update the plugin zip file

### Jobs
- [ ] Create CreateBackup job (download from WP, archive, upload to storage, retention)
- [ ] Create RestoreBackup job (download from storage, verify checksum, restore via WP API)
- [ ] Create NotifyBackupFailed job (notification on failure)
- [ ] Add scheduler entries (check scheduled backups every 15 min, clean expired daily)

### UI Pages
- [ ] Build SiteBackups page (quick actions, schedule status, backup history, storage usage)
- [ ] Build BackupScheduleForm modal (frequency, time, type, storage, retention)
- [ ] Build storage destinations section in General Settings
- [ ] Build StorageDestinationForm modal (type selector, config fields, Dropbox OAuth button)
- [ ] Build RestoreConfirmation modal (warning, checkbox, backup-first option)
- [ ] Add download button (generate temporary download link)
- [ ] Add lock/unlock functionality
- [ ] Update site card with backup status
- [ ] Update site overview with last backup info

### Pre-Update Integration
- [ ] Wire backup-before-updates into plugin/theme/core update actions
- [ ] Lock pre-update backups automatically

### Install Dependencies
- [ ] `composer require aws/aws-sdk-php` (for S3 driver)

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup\Storage;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\DropboxDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DropboxDriverTest extends TestCase
{
    use RefreshDatabase;

    private StorageDestination $destination;

    private DropboxDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = StorageDestination::factory()->create([
            'type' => 'dropbox',
            'config' => [
                'access_token' => encrypt('fake-access-token'),
                'refresh_token' => encrypt('fake-refresh-token'),
                'app_key' => encrypt('fake-app-key'),
                'app_secret' => encrypt('fake-app-secret'),
                'base_path' => '/Backups/Sites',
            ],
        ]);

        $this->driver = new DropboxDriver($this->destination);
    }

    #[Test]
    public function test_checks_current_account(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/users/get_current_account' => Http::response([
                'account_id' => 'dbid:AAH4f99T0taONIb-OurWxbNQ6ywGRopQngc',
                'name' => ['display_name' => 'Test User'],
            ]),
        ]);

        $this->assertTrue($this->driver->test());
    }

    #[Test]
    public function test_returns_false_without_account_id(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/users/get_current_account' => Http::response([]),
        ]);

        $this->assertFalse($this->driver->test());
    }

    #[Test]
    public function exists_returns_true_when_metadata_found(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/get_metadata' => Http::response([
                '.tag' => 'file',
                'name' => 'backup.zip',
                'size' => 1024,
            ]),
        ]);

        $this->assertTrue($this->driver->exists('site1/backup.zip'));
    }

    #[Test]
    public function exists_returns_false_on_api_error(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/get_metadata' => Http::response(
                ['error_summary' => 'path/not_found/'],
                409
            ),
        ]);

        $this->assertFalse($this->driver->exists('nonexistent.zip'));
    }

    #[Test]
    public function size_returns_file_size(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/get_metadata' => Http::response([
                '.tag' => 'file',
                'name' => 'backup.zip',
                'size' => 5242880,
            ]),
        ]);

        $this->assertEquals(5242880, $this->driver->size('backup.zip'));
    }

    #[Test]
    public function delete_calls_delete_v2(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/delete_v2' => Http::response([
                'metadata' => ['.tag' => 'file', 'name' => 'backup.zip'],
            ]),
        ]);

        $this->driver->delete('backup.zip');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'delete_v2')
                && $request->data()['path'] === '/Backups/Sites/backup.zip';
        });
    }

    #[Test]
    public function list_returns_entries(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [
                    ['.tag' => 'file', 'name' => 'backup1.zip', 'path_display' => '/Backups/Sites/site1/backup1.zip', 'size' => 1024, 'server_modified' => '2026-03-01T10:00:00Z'],
                    ['.tag' => 'folder', 'name' => 'incremental', 'path_display' => '/Backups/Sites/site1/incremental'],
                ],
                'has_more' => false,
            ]),
        ]);

        $files = $this->driver->list('site1');

        $this->assertCount(2, $files);
        $this->assertEquals('backup1.zip', $files[0]['name']);
        $this->assertFalse($files[0]['is_dir']);
        $this->assertEquals('incremental', $files[1]['name']);
        $this->assertTrue($files[1]['is_dir']);
    }

    #[Test]
    public function upload_small_file_uses_simple_upload(): void
    {
        Http::fake([
            'content.dropboxapi.com/2/files/upload' => Http::response([
                '.tag' => 'file',
                'name' => 'small.zip',
            ]),
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'dbx_test_');
        file_put_contents($tempFile, str_repeat('x', 100)); // 100 bytes, well under 8MB threshold

        $this->driver->upload($tempFile, 'site1/small.zip');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'files/upload');
        });

        unlink($tempFile);
    }

    #[Test]
    public function temporary_url_returns_link(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/get_temporary_link' => Http::response([
                'link' => 'https://dl.dropboxusercontent.com/temporary/file.zip',
            ]),
        ]);

        $url = $this->driver->temporaryUrl('file.zip');

        $this->assertEquals('https://dl.dropboxusercontent.com/temporary/file.zip', $url);
    }

    #[Test]
    public function list_folders_returns_only_folders(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [
                    ['.tag' => 'folder', 'name' => 'site1', 'path_display' => '/Backups/site1'],
                    ['.tag' => 'file', 'name' => 'notes.txt', 'path_display' => '/Backups/notes.txt', 'size' => 50],
                    ['.tag' => 'folder', 'name' => 'site2', 'path_display' => '/Backups/site2'],
                ],
                'has_more' => false,
            ]),
        ]);

        $folders = $this->driver->listFolders('/Backups');

        $this->assertCount(2, $folders);
        $this->assertEquals('site1', $folders[0]['name']);
        $this->assertEquals('site2', $folders[1]['name']);
    }

    #[Test]
    public function api_error_throws_runtime_exception(): void
    {
        Http::fake([
            'api.dropboxapi.com/*' => Http::response(
                ['error_summary' => 'internal_server_error'],
                500
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Dropbox API error \[500\]/');

        $this->driver->list('');
    }

    #[Test]
    public function full_path_prepends_base_path(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/files/get_metadata' => Http::response([
                '.tag' => 'file',
                'name' => 'test.zip',
                'size' => 100,
            ]),
        ]);

        $this->driver->exists('deep/nested/test.zip');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['path'] ?? '') === '/Backups/Sites/deep/nested/test.zip';
        });
    }
}

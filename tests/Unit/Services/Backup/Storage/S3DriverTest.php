<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup\Storage;

use App\Services\Backup\Storage\S3Driver;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class S3DriverTest extends TestCase
{
    private S3Client $mockClient;

    private S3Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(S3Client::class);

        // Create driver and inject mock client via reflection
        $this->driver = $this->createDriverWithMockClient([
            'bucket' => 'test-bucket',
            'base_path' => 'backups',
            'region' => 'eu-central-1',
        ]);
    }

    private function createDriverWithMockClient(array $config): S3Driver
    {
        // Use reflection to bypass constructor (which creates real S3Client)
        $driver = (new \ReflectionClass(S3Driver::class))->newInstanceWithoutConstructor();

        $ref = new \ReflectionClass($driver);

        $clientProp = $ref->getProperty('client');
        $clientProp->setValue($driver, $this->mockClient);

        $bucketProp = $ref->getProperty('bucket');
        $bucketProp->setValue($driver, $config['bucket']);

        $basePathProp = $ref->getProperty('basePath');
        $basePathProp->setValue($driver, trim($config['base_path'] ?? '', '/'));

        return $driver;
    }

    #[Test]
    public function upload_calls_put_object(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 's3_test_');
        file_put_contents($tempFile, str_repeat('x', 100)); // Small file

        $this->mockClient->shouldReceive('putObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Bucket'] === 'test-bucket'
                && $args['Key'] === 'backups/site1/backup.zip'
                && $args['SourceFile'] === $tempFile
            ))
            ->andReturn(new Result([]));

        $this->driver->upload($tempFile, 'site1/backup.zip');

        unlink($tempFile);
    }

    #[Test]
    public function download_calls_get_object(): void
    {
        $downloadPath = sys_get_temp_dir().'/s3_download_'.uniqid();

        $this->mockClient->shouldReceive('getObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Bucket'] === 'test-bucket'
                && $args['Key'] === 'backups/site1/backup.zip'
                && $args['SaveAs'] === $downloadPath
            ))
            ->andReturn(new Result([]));

        $this->driver->download('site1/backup.zip', $downloadPath);

        @unlink($downloadPath);
    }

    #[Test]
    public function delete_calls_delete_object(): void
    {
        $this->mockClient->shouldReceive('deleteObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Bucket'] === 'test-bucket'
                && $args['Key'] === 'backups/old-backup.zip'
            ))
            ->andReturn(new Result([]));

        $this->driver->delete('old-backup.zip');
    }

    #[Test]
    public function exists_calls_does_object_exist(): void
    {
        $this->mockClient->shouldReceive('doesObjectExistV2')
            ->once()
            ->with('test-bucket', 'backups/check.zip')
            ->andReturn(true);

        $this->assertTrue($this->driver->exists('check.zip'));
    }

    #[Test]
    public function exists_returns_false_for_missing_file(): void
    {
        $this->mockClient->shouldReceive('doesObjectExistV2')
            ->once()
            ->with('test-bucket', 'backups/missing.zip')
            ->andReturn(false);

        $this->assertFalse($this->driver->exists('missing.zip'));
    }

    #[Test]
    public function size_returns_content_length(): void
    {
        $this->mockClient->shouldReceive('headObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'backups/sized.zip'))
            ->andReturn(new Result(['ContentLength' => 5242880]));

        $this->assertEquals(5242880, $this->driver->size('sized.zip'));
    }

    #[Test]
    public function test_calls_head_bucket(): void
    {
        $this->mockClient->shouldReceive('headBucket')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Bucket'] === 'test-bucket'))
            ->andReturn(new Result([]));

        $this->assertTrue($this->driver->test());
    }

    #[Test]
    public function temporary_url_creates_presigned_request(): void
    {
        $mockCommand = Mockery::mock(CommandInterface::class);
        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('__toString')->andReturn('https://test-bucket.s3.amazonaws.com/backups/file.zip?signed=1');
        $mockRequest->shouldReceive('getUri')->andReturn($mockUri);

        $this->mockClient->shouldReceive('getCommand')
            ->once()
            ->with('GetObject', Mockery::on(fn ($args) => $args['Key'] === 'backups/file.zip'))
            ->andReturn($mockCommand);

        $this->mockClient->shouldReceive('createPresignedRequest')
            ->once()
            ->with($mockCommand, '+60 minutes')
            ->andReturn($mockRequest);

        $url = $this->driver->temporaryUrl('file.zip');

        $this->assertStringContainsString('test-bucket', $url);
    }

    #[Test]
    public function list_returns_files_and_folders(): void
    {
        $lastModified = new \DateTime('2026-03-01');

        $this->mockClient->shouldReceive('listObjectsV2')
            ->once()
            ->andReturn(new Result([
                'Contents' => [
                    ['Key' => 'backups/site1/backup1.zip', 'Size' => 1024, 'LastModified' => $lastModified],
                ],
                'CommonPrefixes' => [
                    ['Prefix' => 'backups/site1/incremental/'],
                ],
            ]));

        $files = $this->driver->list('site1');

        $this->assertCount(2, $files);
        $this->assertEquals('backup1.zip', $files[0]['name']);
        $this->assertFalse($files[0]['is_dir']);
        $this->assertEquals('incremental', $files[1]['name']);
        $this->assertTrue($files[1]['is_dir']);
    }

    #[Test]
    public function full_path_prepends_base_path(): void
    {
        // Verify via exists() which calls fullPath internally
        $this->mockClient->shouldReceive('doesObjectExistV2')
            ->once()
            ->with('test-bucket', 'backups/deep/nested/file.zip')
            ->andReturn(true);

        $this->driver->exists('deep/nested/file.zip');
    }
}

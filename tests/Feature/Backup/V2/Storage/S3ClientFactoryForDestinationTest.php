<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use App\Backup\V2\Storage\S3ClientFactory;
use App\Models\StorageDestination;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * S3ClientFactory::forDestination — builds a real Aws\S3\S3Client from a site's
 * StorageDestination, decrypting the ENCRYPTED key/secret exactly like V1 S3Driver
 * and resolving endpoint/region/bucket. No secret is duplicated in code.
 */
class S3ClientFactoryForDestinationTest extends TestCase
{
    public function test_hetzner_destination_decrypts_credentials_and_resolves_endpoint(): void
    {
        // Credentials are stored Laravel-ENCRYPTED (exactly as the V1 driver expects).
        $destination = StorageDestination::factory()->make([
            'type' => 'hetzner_objectstorage',
            'config' => [
                'key' => encrypt('AKIAHETZNERKEY'),
                'secret' => encrypt('hetzner-secret-value'),
                'region' => 'fsn1',
                'bucket' => 'my-backup-bucket',
            ],
        ]);

        $factory = S3ClientFactory::forDestination($destination);
        $client = $factory->client();

        $creds = $client->getCredentials()->wait();
        $this->assertSame('AKIAHETZNERKEY', $creds->getAccessKeyId());
        $this->assertSame('hetzner-secret-value', $creds->getSecretKey());

        $this->assertSame('fsn1', $client->getRegion());
        // Endpoint resolved from the SAME map V1 uses (StorageFactory::endpointFor).
        $this->assertSame('https://fsn1.your-objectstorage.com', (string) $client->getEndpoint());
        $this->assertSame('my-backup-bucket', $factory->bucket());
    }

    public function test_custom_endpoint_in_config_takes_precedence(): void
    {
        $destination = StorageDestination::factory()->make([
            'type' => 's3',
            'config' => [
                'key' => encrypt('AKIACUSTOM'),
                'secret' => encrypt('custom-secret'),
                'region' => 'us-east-1',
                'endpoint' => 'https://minio.internal:9000',
                'use_path_style' => true,
                'bucket' => 'custom-bucket',
            ],
        ]);

        $client = S3ClientFactory::forDestination($destination)->client();

        $this->assertSame('https://minio.internal:9000', (string) $client->getEndpoint());
        $this->assertSame('us-east-1', $client->getRegion());
    }

    public function test_plain_aws_s3_destination_is_region_native_without_custom_endpoint(): void
    {
        $destination = StorageDestination::factory()->make([
            'type' => 's3',
            'config' => [
                'key' => encrypt('AKIAAWS'),
                'secret' => encrypt('aws-secret'),
                'region' => 'eu-central-1',
                'bucket' => 'aws-bucket',
            ],
        ]);

        $client = S3ClientFactory::forDestination($destination)->client();

        $this->assertSame('eu-central-1', $client->getRegion());
        // Region-native AWS endpoint (no custom host injected).
        $this->assertStringContainsString('amazonaws.com', (string) $client->getEndpoint());
    }

    public function test_non_s3_type_is_refused(): void
    {
        $destination = StorageDestination::factory()->make([
            'type' => 'dropbox',
            'config' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        S3ClientFactory::forDestination($destination);
    }
}

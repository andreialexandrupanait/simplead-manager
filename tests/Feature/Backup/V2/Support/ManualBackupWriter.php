<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Storage\ObjectLayout;
use Aws\S3\S3Client;
use ZipArchive;

/**
 * Writes a COMPLETE, self-consistent V2 backup layout straight to (lab) storage —
 * real file-chunk zips + real gzipped-SQL DB segments + manifest.json /
 * checksums.json / metadata.json / _COMPLETE — with matching sizes and sha256s.
 *
 * Deterministic and transport-only (no plugin, no WP), so the verification tests
 * can prove BOTH the archive-open path (zip) and the DB-parse path (SQL gzip)
 * without spike-wp, and can corrupt an object surgically to prove detection.
 */
final class ManualBackupWriter
{
    /**
     * @return array<string,mixed> the manifest that was written
     */
    public static function write(S3Client $s3, string $bucket, ObjectLayout $layout, int $fileChunks = 2, int $dbSegments = 1): array
    {
        $objects = [];

        for ($i = 0; $i < $fileChunks; $i++) {
            [$body, $sha] = self::zipChunk($i);
            $key = $layout->files("chunk_{$i}.zip");
            self::put($s3, $bucket, $key, $body);
            $objects[] = ['kind' => 'files', 'key' => $key, 'chunk_index' => $i, 'size' => strlen($body), 'sha256' => $sha];
        }

        for ($i = 0; $i < $dbSegments; $i++) {
            [$body, $sha] = self::dbSegment($i);
            $key = $layout->database("chunk_{$i}.sql.gz");
            self::put($s3, $bucket, $key, $body);
            $objects[] = ['kind' => 'database', 'key' => $key, 'chunk_index' => $i, 'size' => strlen($body), 'sha256' => $sha];
        }

        $manifest = [
            'format_version' => 'simplead-backup/1',
            'engine' => 'simplead-backup',
            'backup_type' => 'full',
            'objects' => $objects,
            'database' => ['name' => 'wp', 'table_count' => 2, 'total_rows' => 3],
            'environment' => ['consistent_snapshot' => true, 'wp_version' => '6.9'],
        ];
        $manifestJson = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $checksums = ['algorithm' => 'sha256', 'objects' => []];
        foreach ($objects as $o) {
            $checksums['objects'][$o['key']] = ['sha256' => $o['sha256'], 'size' => $o['size']];
        }

        self::put($s3, $bucket, $layout->manifest(), $manifestJson);
        self::put($s3, $bucket, $layout->checksums(), (string) json_encode($checksums, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        self::put($s3, $bucket, $layout->metadata(), (string) json_encode(['backup_type' => 'full', 'object_count' => count($objects)], JSON_UNESCAPED_SLASHES));
        self::put($s3, $bucket, $layout->completeMarker(), (string) json_encode([
            'completed_at' => now()->toIso8601String(),
            'manifest_sha256' => hash('sha256', $manifestJson),
            'object_count' => count($objects),
        ], JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * @return array{0:string,1:string} [body, sha256]
     */
    private static function zipChunk(int $index): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'manzip_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("wp-content/uploads/chunk{$index}/a.txt", "chunk {$index} file a");
        $zip->addFromString("wp-content/uploads/chunk{$index}/b.txt", "chunk {$index} file b");
        $zip->close();
        $body = (string) file_get_contents($tmp);
        @unlink($tmp);

        return [$body, hash('sha256', $body)];
    }

    /**
     * @return array{0:string,1:string} [body, sha256]
     */
    private static function dbSegment(int $index): array
    {
        $sql = "-- Simplead Backup dump segment {$index}\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."CREATE TABLE `wp_options` (`option_id` bigint, `option_name` varchar(191));\n"
            ."INSERT INTO `wp_options` VALUES (1,'siteurl'),(2,'blogname');\n"
            ."CREATE TABLE `wp_posts` (`ID` bigint, `post_title` text);\n"
            ."INSERT INTO `wp_posts` VALUES (1,'Hello World');\n"
            ."-- End of Simplead Backup dump\n";
        $body = (string) gzencode($sql, 6);

        return [$body, hash('sha256', $body)];
    }

    private static function put(S3Client $s3, string $bucket, string $key, string $body): void
    {
        $s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $body]);
    }
}

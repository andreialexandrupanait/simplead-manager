<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles direct uploads from WP to remote storage (S3 multipart, chunked push to relay).
 */
class SAM_Direct_Uploader {

    /**
     * Upload a local file to S3 via presigned multipart URLs.
     * Each part is streamed via cURL (CURLOPT_INFILE) to avoid loading into memory.
     *
     * @param string $file_path      Path to the local file to upload.
     * @param array  $parts          Array of ['part_number' => int, 'url' => string, 'start' => int, 'end' => int].
     * @param string $callback_url   Manager URL to report progress after each part.
     * @param string $callback_token HMAC token for authenticating callbacks.
     * @param int    $backup_id      Backup ID for progress reporting.
     * @return array Array of ['PartNumber' => int, 'ETag' => string] for each part.
     */
    public static function upload_s3_multipart(
        string $file_path,
        array $parts,
        string $callback_url,
        string $callback_token,
        int $backup_id
    ): array {
        $etags = [];
        $total_parts = count($parts);

        foreach ($parts as $index => $part) {
            $etag = self::upload_s3_part(
                $file_path,
                $part['url'],
                $part['start'],
                $part['end'] - $part['start'],
                3
            );

            $etags[] = [
                'PartNumber' => $part['part_number'],
                'ETag' => $etag,
            ];

            // Report progress
            self::report_progress($callback_url, $callback_token, $backup_id, [
                'parts_done' => $index + 1,
                'parts_total' => $total_parts,
                'strategy' => 's3_multipart',
            ]);
        }

        return $etags;
    }

    /**
     * Upload a single S3 part with retries.
     */
    private static function upload_s3_part(string $file_path, string $presigned_url, int $offset, int $length, int $max_retries): string {
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            try {
                $fh = fopen($file_path, 'rb');
                if (!$fh) {
                    throw new \RuntimeException("Cannot open file: {$file_path}");
                }
                fseek($fh, $offset);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $presigned_url,
                    CURLOPT_PUT => true,
                    CURLOPT_INFILE => $fh,
                    CURLOPT_INFILESIZE => $length,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => 600,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/octet-stream',
                    ],
                ]);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                fclose($fh);

                if ($curl_error) {
                    throw new \RuntimeException("cURL error: {$curl_error}");
                }

                if ($http_code < 200 || $http_code >= 300) {
                    throw new \RuntimeException("S3 PUT failed with HTTP {$http_code}: {$response}");
                }

                // Extract ETag from response headers
                if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $response, $matches)) {
                    return $matches[1];
                }

                throw new \RuntimeException('No ETag in S3 response headers');
            } catch (\Throwable $e) {
                $last_error = $e;
                if ($attempt < $max_retries) {
                    sleep(pow(2, $attempt)); // exponential backoff: 2, 4, 8
                }
            }
        }

        throw new \RuntimeException(
            "Failed to upload S3 part after {$max_retries} attempts: " . $last_error->getMessage()
        );
    }

    /**
     * Upload a local file in chunks via HTTP POST to a relay endpoint.
     * Used for Dropbox/local storage where WP can't upload directly.
     *
     * @param string $file_path      Path to the local file to upload.
     * @param string $upload_url     Manager relay URL.
     * @param string $upload_token   HMAC token for authenticating chunk uploads.
     * @param int    $chunk_size     Bytes per chunk.
     * @param string $callback_url   Manager URL to report progress after each chunk.
     * @param string $callback_token HMAC token for authenticating callbacks.
     * @param int    $backup_id      Backup ID for progress reporting.
     */
    public static function upload_chunked_push(
        string $file_path,
        string $upload_url,
        string $upload_token,
        int $chunk_size,
        string $callback_url,
        string $callback_token,
        int $backup_id
    ): void {
        $file_size = filesize($file_path);
        $total_chunks = (int) ceil($file_size / $chunk_size);
        $fh = fopen($file_path, 'rb');

        if (!$fh) {
            throw new \RuntimeException("Cannot open file: {$file_path}");
        }

        try {
            $chunk_index = 0;
            $offset = 0;

            while ($offset < $file_size) {
                $length = min($chunk_size, $file_size - $offset);
                $data = fread($fh, $length);
                $is_last = ($offset + $length) >= $file_size;

                self::send_chunk($upload_url, $upload_token, $data, $offset, $is_last, 3);

                $chunk_index++;
                $offset += $length;

                // Report progress
                self::report_progress($callback_url, $callback_token, $backup_id, [
                    'parts_done' => $chunk_index,
                    'parts_total' => $total_chunks,
                    'bytes_uploaded' => $offset,
                    'bytes_total' => $file_size,
                    'strategy' => 'chunked_push',
                ]);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Send a single chunk to the relay endpoint with retries.
     */
    private static function send_chunk(string $url, string $token, string $data, int $offset, bool $is_last, int $max_retries): void {
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $data,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/octet-stream',
                        'X-Backup-Token: ' . $token,
                        'X-Chunk-Offset: ' . $offset,
                        'X-Chunk-Is-Last: ' . ($is_last ? '1' : '0'),
                    ],
                ]);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                if ($curl_error) {
                    throw new \RuntimeException("cURL error: {$curl_error}");
                }

                if ($http_code < 200 || $http_code >= 300) {
                    throw new \RuntimeException("Relay returned HTTP {$http_code}: {$response}");
                }

                return;
            } catch (\Throwable $e) {
                $last_error = $e;
                if ($attempt < $max_retries) {
                    sleep(pow(2, $attempt));
                }
            }
        }

        throw new \RuntimeException(
            "Failed to send chunk at offset {$offset} after {$max_retries} attempts: " . $last_error->getMessage()
        );
    }

    /**
     * Report progress back to Manager via callback URL.
     */
    private static function report_progress(string $callback_url, string $callback_token, int $backup_id, array $data): void {
        if (empty($callback_url)) {
            return;
        }

        $payload = json_encode(array_merge($data, ['backup_id' => $backup_id]));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $callback_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Backup-Token: ' . $callback_token,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
        // Fire and forget — don't block upload on callback failures
    }
}

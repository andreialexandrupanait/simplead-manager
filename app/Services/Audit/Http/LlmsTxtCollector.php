<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * llms.txt collector — checks whether /llms.txt exists and looks like a real
 * llms.txt (markdown/plain text) rather than an HTML error page served as 200.
 * Port of src/lib/collectors/llmstxt.ts.
 */
final class LlmsTxtCollector
{
    public const EXCERPT_BYTES = 1024;

    private static function looksLikeHtml(string $body, ?string $contentType): bool
    {
        if ($contentType !== null && $contentType !== '' && preg_match('#text/html|application/xhtml#i', $contentType) === 1) {
            return true;
        }
        $head = strtolower(substr(ltrim($body), 0, 256));

        return str_starts_with($head, '<!doctype')
            || str_starts_with($head, '<html')
            || str_starts_with($head, '<head')
            || str_starts_with($head, '<body');
    }

    /**
     * @return array{found: bool, status: int|null, sizeBytes: int, looksValid: bool, excerpt: string}
     */
    public function collect(string $url): array
    {
        $llmsUrl = UrlHelper::resolvePath($url, '/llms.txt');
        $status = null;
        $body = '';
        $contentType = null;
        try {
            $res = Http::get($llmsUrl);
            $status = $res->status();
            $contentType = $res->header('Content-Type');
            if ($res->successful()) {
                $body = $res->body();
            }
        } catch (ConnectionException) {
            $status = null;
        }

        $found = $status === 200;
        $trimmed = trim($body);
        $looksValid = $found && $trimmed !== '' && ! self::looksLikeHtml($body, $contentType);

        return [
            'found' => $found,
            'status' => $status,
            'sizeBytes' => $found ? strlen($body) : 0,
            'looksValid' => $looksValid,
            'excerpt' => substr($trimmed, 0, self::EXCERPT_BYTES),
        ];
    }
}

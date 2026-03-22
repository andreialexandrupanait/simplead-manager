<?php
/**
 * Standalone OPcache flush — called directly via HTTP after connector push.
 * NOT loaded through WordPress. Bypasses OPcache entirely since this file
 * is freshly extracted from the ZIP and has no cached OPcache entry.
 *
 * Secured by one-time trigger file included in the ZIP.
 */

$trigger = __DIR__ . '/opcache-flush-trigger';
if (!file_exists($trigger)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No flush trigger']);
    exit;
}

@unlink($trigger);

$cleared = 0;
$patterns = [
    __DIR__ . '/*.php',
    __DIR__ . '/includes/*.php',
    __DIR__ . '/includes/endpoints/*.php',
];

foreach ($patterns as $pattern) {
    foreach (glob($pattern) ?: [] as $f) {
        @touch($f);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($f, true);
        }
        $cleared++;
    }
}

// Also invalidate MU-plugin if it exists
$muPlugin = dirname(__DIR__, 2) . '/mu-plugins/simplead-security.php';
if (file_exists($muPlugin)) {
    @touch($muPlugin);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($muPlugin, true);
    }
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}
clearstatcache(true);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'cleared' => $cleared]);

<?php

// Pilot Pas 2a — INVENTAR PREVIEW feaagalati.ro (POST non-distructiv, nu citeste/urca continut).
// Doar numara fisierele + estimeaza dimensiunea (un lstat per fisier). NU face backup.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$s = App\Models\Site::find(32); // feaagalati.ro
if (! $s) {
    fwrite(STDERR, "Site 32 not found\n");
    exit(1);
}

$secret = $s->api_secret;
$key = $s->api_key;
$base = rtrim($s->url, '/');
$route = '/simplead-backup/v1/files/inventory';
$url = $base.'/wp-json'.$route;

$ts = time();
$nonce = bin2hex(random_bytes(16));
$body = json_encode(['preview' => true, 'include_defaults' => true]);
$sts = implode('|', ['POST', $route, $ts, $nonce, $body]);
$sig = hash_hmac('sha256', $sts, $secret);

try {
    $r = Http::withHeaders([
        'X-SAM-Backup-Key' => $key,
        'X-SAM-Backup-Timestamp' => (string) $ts,
        'X-SAM-Backup-Nonce' => $nonce,
        'X-SAM-Backup-Signature' => $sig,
        'Content-Type' => 'application/json',
    ])->withBody($body, 'application/json')->timeout(180)->post($url);

    echo 'HTTP '.$r->status()."\n";
    $j = $r->json();
    if (is_array($j)) {
        $mb = fn ($b) => number_format(((int) $b) / 1048576, 1).' MB';
        echo 'total_files    = '.($j['total_files'] ?? '?')."\n";
        echo 'total_bytes    = '.(isset($j['total_bytes']) ? $mb($j['total_bytes']) : '?')."\n";
        echo 'excluded_files = '.($j['excluded_files'] ?? '?')."\n";
        echo 'excluded_bytes = '.(isset($j['excluded_bytes']) ? $mb($j['excluded_bytes']) : '?')."\n";
        echo 'exclusion_hash = '.substr((string) ($j['exclusion_policy_hash'] ?? '?'), 0, 16)."\n";
        if (isset($j['error']) || isset($j['code'])) {
            echo 'raw = '.json_encode($j)."\n";
        }
    } else {
        echo substr($r->body(), 0, 1000)."\n";
    }
} catch (\Throwable $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}

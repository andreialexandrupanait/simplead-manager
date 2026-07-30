<?php
// Pilot Pas 1 — SONDAJ capabilities feaagalati.ro (GET non-distructiv, nu face backup).
// Citeste credentialele site-ului din manager, semneaza HMAC, cere /capabilities.
// NU afiseaza secrete. Ruleaza in containerul de productie (are acces la DB + internet).
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$s = App\Models\Site::find(32); // feaagalati.ro
if (!$s) { fwrite(STDERR, "Site 32 not found\n"); exit(1); }

$secret = $s->api_secret;
$key    = $s->api_key;
$base   = rtrim($s->url, '/');
$route  = '/simplead-backup/v1/capabilities';
$url    = $base . '/wp-json' . $route;

$ts    = time();
$nonce = bin2hex(random_bytes(16));
$body  = '';
$sts   = implode('|', ['GET', $route, $ts, $nonce, $body]);
$sig   = hash_hmac('sha256', $sts, $secret);

try {
    $r = Http::withHeaders([
        'X-SAM-Backup-Key'       => $key,
        'X-SAM-Backup-Timestamp' => (string) $ts,
        'X-SAM-Backup-Nonce'     => $nonce,
        'X-SAM-Backup-Signature' => $sig,
    ])->timeout(30)->get($url);

    echo "HTTP " . $r->status() . "\n";
    $j = $r->json();
    if (is_array($j)) {
        foreach ($j as $k => $v) {
            echo $k . " = " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
        }
    } else {
        echo substr($r->body(), 0, 1000) . "\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

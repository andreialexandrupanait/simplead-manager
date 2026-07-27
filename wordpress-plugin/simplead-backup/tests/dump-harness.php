<?php

declare(strict_types=1);

/**
 * Lab-only CLI harness that drives SAM_Backup_Consistent_Dumper against an arbitrary
 * MySQL/MariaDB server, so the SAME production dump code can be validated on both engines
 * without going through WordPress/HTTP.
 *
 * Usage:
 *   php dump-harness.php --host=H --port=P --user=U --pass=W --name=DB \
 *       --out=/dir/for/segments [--restore-out=/path/restore.sql] [--budget=120] [--segment=8388608]
 *
 * Prints the dump manifest as JSON on stdout. If --restore-out is given, it also writes the
 * concatenated, gunzipped restore SQL there (segments joined in order) for import verification.
 */

require __DIR__ . '/../includes/db/class-consistent-dumper.php';

$opts = getopt('', array(
    'host::', 'port::', 'user::', 'pass::', 'name::',
    'out:', 'restore-out::', 'budget::', 'segment::', 'batch::',
));

$cfg = array(
    'host'          => $opts['host'] ?? '127.0.0.1',
    'port'          => (int) ($opts['port'] ?? 3306),
    'user'          => $opts['user'] ?? 'root',
    'password'      => $opts['pass'] ?? '',
    'database'      => $opts['name'] ?? 'wordpress',
    'time_budget'   => (float) ($opts['budget'] ?? 300),
    'segment_bytes' => (int) ($opts['segment'] ?? (8 * 1024 * 1024)),
    'batch_rows'    => (int) ($opts['batch'] ?? 1000),
);

$out = $opts['out'] ?? null;
if ($out === null) {
    fwrite(STDERR, "--out is required\n");
    exit(2);
}

try {
    $dumper   = new SAM_Backup_Consistent_Dumper($cfg);
    $manifest = $dumper->dump($out);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DUMP ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

// Optionally materialise the restorable SQL by concatenating gunzipped segments in order.
if (!empty($opts['restore-out'])) {
    $fp = fopen($opts['restore-out'], 'wb');
    if ($fp === false) {
        fwrite(STDERR, "cannot open restore-out\n");
        exit(1);
    }
    foreach ($manifest['segments'] as $seg) {
        $gz = gzopen($seg['path'], 'rb');
        if ($gz === false) {
            fwrite(STDERR, 'cannot read segment ' . $seg['path'] . "\n");
            exit(1);
        }
        while (!gzeof($gz)) {
            $buf = gzread($gz, 262144);
            if ($buf === false) {
                fwrite(STDERR, 'gzread failed on ' . $seg['path'] . "\n");
                exit(1);
            }
            // Loop until the whole buffer is flushed — fwrite may write fewer bytes.
            $len = strlen($buf);
            $off = 0;
            while ($off < $len) {
                $w = fwrite($fp, substr($buf, $off));
                if ($w === false || $w === 0) {
                    fwrite(STDERR, "fwrite short/failed while building restore.sql\n");
                    exit(1);
                }
                $off += $w;
            }
        }
        gzclose($gz);
    }
    fflush($fp);
    fclose($fp);
    $manifest['restore_out'] = $opts['restore-out'];
}

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
